<?php

declare(strict_types=1);

namespace Webhooks;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;
use Webhooks\Console\AsyncApiCommand;
use Webhooks\Console\EgressIpsCommand;
use Webhooks\Console\PartitionMaintenanceCommand;
use Webhooks\Console\RefreshEndpointHealthCommand;
use Webhooks\Console\RevokeRotatedSecretsCommand;
use Webhooks\Listeners\WebhookServerEventSubscriber;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Delivery\SubscriptionDeliveryGate;
use Webhooks\Platform\Health\RefreshEndpointHealthOnDelivery;
use Webhooks\Platform\Policies\WebhookSubscriptionPolicy;
use Webhooks\Platform\Transform\DeclarativePayloadTransformer;
use Webhooks\Platform\Transform\PayloadTransformer;
use Webhooks\Server\Delivery\DeliveryGate;
use Webhooks\Support\ScheduleCadence;

final class WebhooksServiceProvider extends ServiceProvider
{
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
        $this->mergeConfigFrom(__DIR__.'/../config/webhooks.php', 'webhooks');

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
            $schedule->command('webhooks:partition-maintenance')->daily();

            // A delivery revokes its own endpoint's expired secret, so this only has to
            // catch the endpoints that go quiet: without it, an endpoint that stops
            // sending the day it rotates would keep the old secret valid for ever.
            $schedule->command('webhooks:revoke-rotated-secrets')->hourly()->withoutOverlapping();

            // A finished delivery only refreshes ITS OWN endpoint's cached health, so an
            // endpoint whose traffic dries up would keep the last score a delivery left
            // frozen forever and the status board would silently lie. When continuous
            // scoring is on, sweep every active endpoint on a cadence so a gone-quiet
            // endpoint decays to its true band. Off when health scoring is off — nothing
            // caches a score then, so there is nothing to keep fresh.
            if (Config::boolean('webhooks.platform.health.enabled', false)) {
                $event = $schedule->command('webhooks:refresh-endpoint-health')->withoutOverlapping();

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
                AsyncApiCommand::class,
                EgressIpsCommand::class,
                RefreshEndpointHealthCommand::class,
                RevokeRotatedSecretsCommand::class,
            ]);
        }
    }

    /**
     * Wire the self-service authorization surface — the manage-webhook-endpoints
     * ability plus the row-level subscription policy — but only when a host has
     * opted a tenant in to managing its own endpoints. The gate is permissive by
     * default (any authenticated tenant), deferring to a host 'webhooks.manage'
     * ability when one is defined, so the layer is turnkey yet tightenable.
     */
    private function registerSelfServiceAuthorization(): void
    {
        if (! Config::boolean('webhooks.platform.self_service.enabled', false)) {
            return;
        }

        Gate::define('manage-webhook-endpoints', static function (Authenticatable $user): bool {
            if (! Gate::has('webhooks.manage')) {
                return true;
            }

            return Gate::forUser($user)->allows('webhooks.manage');
        });

        Gate::policy(WebhookSubscription::class, WebhookSubscriptionPolicy::class);
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/webhooks.php' => config_path('webhooks.php'),
        ], 'webhooks-config');

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
        $this->publishes($this->migrationsIn(), 'webhooks-migrations');
        $this->publishes($this->migrationsIn('client'), 'webhooks-client-migrations');
        $this->publishes($this->migrationsIn('server'), 'webhooks-server-migrations');
        $this->publishes($this->migrationsIn('dashboard'), 'webhooks-dashboard-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/webhooks'),
        ], 'webhooks-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/webhooks'),
        ], 'webhooks-lang');
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
