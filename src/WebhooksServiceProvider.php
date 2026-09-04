<?php

declare(strict_types=1);

namespace Pushery\Webhooks;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\Webhooks\Console\AsyncApiCommand;
use Pushery\Webhooks\Console\EgressIpsCommand;
use Pushery\Webhooks\Console\PartitionMaintenanceCommand;
use Pushery\Webhooks\Console\PruneOrphanedPayloadsCommand;
use Pushery\Webhooks\Console\RefreshEndpointHealthCommand;
use Pushery\Webhooks\Console\RevokeRotatedSecretsCommand;
use Pushery\Webhooks\Database\OwnerKeyDeclaration;
use Pushery\Webhooks\Listeners\WebhookServerEventSubscriber;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Delivery\SubscriptionDeliveryGate;
use Pushery\Webhooks\Platform\Health\RefreshEndpointHealthOnDelivery;
use Pushery\Webhooks\Platform\Policies\WebhookSubscriptionPolicy;
use Pushery\Webhooks\Platform\Transform\DeclarativePayloadTransformer;
use Pushery\Webhooks\Platform\Transform\PayloadTransformer;
use Pushery\Webhooks\Server\Delivery\DeliveryGate;
use Pushery\Webhooks\Support\MergesPackageConfig;
use Pushery\Webhooks\Support\ScheduleCadence;

final class WebhooksServiceProvider extends ServiceProvider
{
    use MergesPackageConfig;

    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergePackageConfig();

        // The declarative payload transformer is the safe, data-driven default the
        // fan-out uses to reshape a body per endpoint before signing.
        $this->app->singleton(PayloadTransformer::class, DeclarativePayloadTransformer::class);

        // SSRF vetting is the shared Core\Ssrf\SsrfGuard (bound by CoreServiceProvider),
        // so the manager auto-resolves it — no local guard binding here.
        $this->app->singleton(WebhookManager::class);
    }

    /**
     * The single switch the Platform management layer hangs off — the event catalog,
     * payload validation, the delivery-log lifecycle subscriber, endpoint health,
     * self-service authorization, the platform commands and the
     * webhook_subscriptions / webhook_deliveries migrations. Off => the send-only
     * Server engine and the receive-only Client layer still work, but none of the
     * subscription/fan-out machinery or its tables load. Exposed so the conditional
     * itself is directly testable.
     */
    public function shouldBoot(): bool
    {
        return Config::boolean('webhooks.platform.enabled', true);
    }

    public function boot(): void
    {
        // The shared webhooks:: view and translation namespaces stay available to
        // every layer that renders through them — the Dashboard read model and the
        // optional management UI both resolve views by this namespace — so they load
        // regardless of the Platform switch.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webhooks');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'webhooks');

        // Publishing is registered regardless of the Platform switch: the config, the
        // views, the translations and each layer's migrations must be publishable by a
        // send-only or receive-only consumer too — the one who has Platform switched OFF
        // is exactly the one who cannot publish the config that switches it back on.
        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->reportOwnerKeyDeclarationDriftAfterMigrating();
        }

        if (! $this->shouldBoot()) {
            return;
        }

        // Every Platform delivery names the endpoint it was built for, so the queued job
        // re-reads it immediately before sending and refuses a delivery whose endpoint was
        // switched off — or deleted — while it waited in the queue. This rebinds the Server
        // layer's OpenDeliveryGate, and ONLY while the Platform layer runs: a send-only or
        // receive-only host keeps the open gate, so a stray subscription_id meta key never
        // queries webhook_subscriptions, a table its config never migrated. Isolation by the
        // config gate, not by SubscriptionDeliveryGate's short-circuit — and the gate is read
        // only at resolve time (a queued job), well after this binding is in place.
        $this->app->singleton(DeliveryGate::class, SubscriptionDeliveryGate::class);

        // Keep the delivery log and circuit breaker in step with the delivery engine
        // by translating its lifecycle events into row updates.
        Event::subscribe(WebhookServerEventSubscriber::class);

        // Recompute an endpoint's cached health score when one of its deliveries
        // finishes. The subscriber is only active when health auto-refresh is enabled;
        // otherwise the cached columns move only via webhooks:refresh-endpoint-health.
        Event::subscribe(RefreshEndpointHealthOnDelivery::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // A DB-per-tenant host turns the package schedule off and drives these commands from
            // its own tenant loop; registering nothing here is what lets it (webhooks.schedule.enabled).
            if (! Config::boolean('webhooks.schedule.enabled', true)) {
                return;
            }

            // The only scheduled command in this package that issues DDL — CREATE TABLE,
            // CREATE TABLE ... PARTITION OF, ATTACH PARTITION, DROP TABLE — and it was the only
            // one without an overlap guard, while the three below it all had one.
            //
            // This line used to name `DETACH PARTITION` as well, and that is the one piece of DDL
            // the command deliberately does not issue.
            // `PartitionManager::drainDefaultPartitionInto()` explains at length why: a
            // non-concurrent DETACH takes ACCESS EXCLUSIVE on the parent and holds it to commit,
            // which would stop the entire outbound delivery path for the length of the move. The
            // drain is built the way it is precisely to avoid it. Naming it here described the
            // shape that was rejected, so a reader weighing the lock cost of this schedule entry
            // weighed the wrong statement.
            //
            // The exposure is narrow and real. ensureMonthlyPartition() short-circuits on
            // partitionExists() before any DDL, so a steady-state daily run creates nothing; the
            // window is the once-a-month CREATE, where PostgreSQL's IF NOT EXISTS is not
            // race-free and two schedulers can collide on pg_class. On MySQL the prune is an
            // unbounded chunked-delete loop instead, so two overlapping runs simply double the
            // delete load on the table everything else is writing to.
            //
            // onOneServer() is the half that matters for a cluster — the Laravel scheduler fires
            // on every app server, so without it the command runs N times in parallel by design.
            // withoutOverlapping() is the half that matters for a long first run over a backlog,
            // where tomorrow's run would start on top of today's. The expiry is generous for the
            // same reason: a lock left behind by a killed run must not silence the command for
            // ever, and a maintenance pass that has not finished in six hours has a problem the
            // next run will not fix by joining in.
            //
            // Neither reaches the DB-per-tenant host that turns the package schedule off and
            // drives the command from its own tenant loop — there is no scheduler lock to take.
            // That host owns its own serialization, which is the trade it made by opting out.
            $schedule->command('webhooks:partition-maintenance')
                ->daily()
                ->withoutOverlapping(360)
                ->onOneServer();

            // A delivery revokes its own endpoint's expired secret, so this only has to
            // catch the endpoints that go quiet: without it, an endpoint that stops
            // sending the day it rotates would keep the old secret valid for ever.
            //
            // The expiry is explicit, and the default is the reason. Laravel's is 1440 minutes, a
            // full day. releaseOnTerminationSignals covers SIGTERM and SIGINT, so an ordinary
            // deploy releases the lock; a SIGKILL, an OOM kill or a hard container stop does not.
            // After one of those, this hourly command is skipped for up to 24 hours, and
            // withoutOverlapping is implemented as skip() — a skipped run is not a failure, so
            // nothing anywhere turns red.
            //
            // 55 minutes: comfortably past any real run of this command, and under the hour that
            // separates two of them, so a stale lock costs exactly one skipped run rather than a day.
            $schedule->command('webhooks:revoke-rotated-secrets')->hourly()->withoutOverlapping(55);

            // A finished delivery only refreshes ITS OWN endpoint's cached health, so an
            // endpoint whose traffic dries up would keep the last score a delivery left
            // frozen forever and the status board would silently lie. When continuous
            // scoring is on, sweep every active endpoint on a cadence so a gone-quiet
            // endpoint decays to its true band. Off when health scoring is off — nothing
            // caches a score then, so there is nothing to keep fresh.
            if (Config::boolean('webhooks.platform.health.enabled', false)) {
                // 30 minutes, for the reason spelled out above the hourly command: twice the
                // default fifteen-minute cadence, so a lock left by a hard kill costs one or two
                // skipped sweeps instead of a day of frozen health scores.
                $event = $schedule->command('webhooks:refresh-endpoint-health')->withoutOverlapping(30);

                // An unknown cadence token falls back to fifteen minutes rather than
                // silently never running.
                ScheduleCadence::apply(
                    $event,
                    Config::string('webhooks.platform.health.refresh', 'everyFifteenMinutes'),
                    'everyFifteenMinutes',
                );
            }
        });

        $this->registerSelfServiceAuthorization();

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PartitionMaintenanceCommand::class,
                PruneOrphanedPayloadsCommand::class,
                AsyncApiCommand::class,
                EgressIpsCommand::class,
                RefreshEndpointHealthCommand::class,
                RevokeRotatedSecretsCommand::class,
            ]);
        }
    }

    /**
     * Say so when `webhooks.platform.owner_key_type` and the `owner_id` column it claims to
     * describe disagree — at the end of the migration that could have caused it.
     *
     * The hook is the migrator's event rather than this package's migration files, and that is the
     * whole point. The contradiction arises exactly when a host forks the two create-table
     * migrations to partition differently or add its own indexes, which is an expected thing to do
     * and not an abuse. In that installation this package's migration files are not in the tree
     * at all, so a guard written inside them would never run on the one host that needs it. A
     * listener on MigrationsEnded hears every migration run, forked or not.
     *
     * It writes a log line rather than console output, and the reason is worth stating because the
     * console would obviously read better. Illuminate\Console\Events\CommandFinished carries an
     * output handle and would have been the natural hook, but the framework deliberately does not
     * dispatch it under tests (Kernel::__construct skips rerouteSymfonyCommandEvents when
     * runningUnitTests), so the branch could never be proven by an arm. The Migrator's own output
     * handle has no public getter. An unprovable guard is one that rots silently, which is the same
     * failure class this check exists to find. The place a reader is told
     * on screen is `webhooks:preflight`, which fails outright.
     *
     * It reports and never fails: the migration's exit code is untouched, so a deploy is
     * never broken by a diagnosis.
     */
    private function reportOwnerKeyDeclarationDriftAfterMigrating(): void
    {
        Event::listen(function (MigrationsEnded $event): void {
            // 'down' rolls the schema back towards nothing; a contradiction found there is
            // about a table on its way out and the reader can do nothing with it.
            if ($event->method !== 'up') {
                return;
            }

            foreach (OwnerKeyDeclaration::contradictions() as $message) {
                Log::warning('[webhooks] '.$message.' Run `php artisan webhooks:preflight` for the full check.');
            }
        });
    }

    /**
     * Wire the self-service authorization surface — the manage-webhook-endpoints
     * ability plus the row-level subscription policy — but only when a host has
     * opted a tenant in to managing its own endpoints. The gate is fail-CLOSED: with
     * no host 'webhooks.manage' ability defined it DENIES, so registering the layer
     * never silently exposes endpoint management to every authenticated user. A host
     * opts a tenant in by defining 'webhooks.manage'.
     */
    private function registerSelfServiceAuthorization(): void
    {
        if (! Config::boolean('webhooks.platform.self_service.enabled', false)) {
            return;
        }

        Gate::define('manage-webhook-endpoints', static function (Authenticatable $user): bool {
            // Fail CLOSED: a host that registers the self-service layer but never defines the
            // 'webhooks.manage' ability must NOT silently expose endpoint management to every
            // authenticated user. With no ability defined, deny — the host opts a tenant in by
            // defining 'webhooks.manage' (see the README's self-service authorization section).
            if (! Gate::has('webhooks.manage')) {
                return false;
            }

            return Gate::forUser($user)->allows('webhooks.manage');
        });

        Gate::policy(WebhookSubscription::class, WebhookSubscriptionPolicy::class);
    }

    private function registerPublishing(): void
    {
        // Every customization group also answers to the umbrella tag `webhooks`, so
        // `vendor:publish --tag=webhooks` hands a host everything it may edit in one command.
        //
        // The umbrella covers config, views and lang and deliberately stops there. Two groups
        // are held out, and neither is an oversight:
        //
        //  - The migration tags. A published migration runs (see registerPublishing's note
        //    below), so sweeping them into an "everything" tag would create the client,
        //    server and dashboard tables in a send-only host that never switched those layers
        //    on. Splitting them by layer exists precisely to prevent that; an umbrella over
        //    them would undo it in one command.
        //  - The two UI variants. `webhooks-ui` and `webhooks-ui-wirekit` write to the SAME
        //    destination on purpose — a host picks exactly one — so publishing both would
        //    resolve to whichever ran last. An umbrella that produces an order-dependent
        //    result is worse than no umbrella.
        //
        // `webhooks-views` already carries the whole resources/views tree, so the narrower
        // dashboard and self-service view tags need no umbrella entry to be reachable.
        $this->publishes([
            __DIR__.'/../config/webhooks.php' => config_path('webhooks.php'),
        ], ['webhooks', 'webhooks-config']);

        // One migration tag per LAYER, each landing its files FLAT in database/migrations.
        //
        // Publishing database/migrations/ as a directory would be the obvious thing and it
        // would be silently broken: vendor:publish mirrors a directory recursively, so the
        // per-layer subdirectories would land as database/migrations/client/… — and
        // Laravel's migrator globs a single level ({path}/*_*.php), so it would never see
        // them. `php artisan migrate` would report nothing to run and the first inbound
        // request would die on a missing table. Mapping file by file to a flat
        // destination is what makes the published migration a migration the migrator runs.
        //
        // The tags are split by layer because a published migration RUNS: handing a
        // send-only consumer the client and dashboard migrations would create tables for
        // layers they never switched on.
        // publishesMigrations(), not publishes(). Since Laravel 11 `vendor:publish` re-dates a
        // published migration to the current timestamp, but only for paths registered through
        // publishesMigrations(), because that is the sole writer of the list VendorPublishCommand
        // consults. With the plain call the setting that switches it on
        // (database.migrations.update_date_on_publish, true in the shipped skeleton) had nothing to
        // act on for this package.
        //
        // What a host got instead were files named 0001_01_01_* in their own database/migrations
        // — the exact class Laravel uses for its own base migrations, so they sort before every
        // application migration in a project upgraded from Laravel 10 and read as though the
        // framework put them there.
        //
        // Everything else is unchanged: publishesMigrations() calls publishes() with the same
        // tags, so the four documented tag names and the per-layer split stay exactly as they
        // are. Only the re-dating is added.
        $this->publishesMigrations($this->migrationsIn(), 'webhooks-migrations');
        $this->publishesMigrations($this->migrationsIn('client'), 'webhooks-client-migrations');
        $this->publishesMigrations($this->migrationsIn('server'), 'webhooks-server-migrations');
        $this->publishesMigrations($this->migrationsIn('dashboard'), 'webhooks-dashboard-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/webhooks'),
        ], ['webhooks', 'webhooks-views']);

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/webhooks'),
        ], ['webhooks', 'webhooks-lang']);
    }

    /**
     * One layer's migration files, each mapped to its own flat destination inside the
     * host's database/migrations — never a directory-to-directory mapping.
     *
     * @return array<string, string>
     */
    private function migrationsIn(string $layer = ''): array
    {
        $directory = __DIR__.'/../database/migrations'.($layer === '' ? '' : "/{$layer}");
        $files = glob("{$directory}/*.php");

        $paths = [];

        foreach ($files === false ? [] : $files as $file) {
            $paths[$file] = database_path('migrations/'.basename($file));
        }

        return $paths;
    }
}
