<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pushery\Webhooks\Client\Http\ForgeryProtectedRoutes;
use Pushery\Webhooks\Client\Http\UnboundReceivingRoutes;
use Pushery\Webhooks\Client\WebhookConfig;
use Pushery\Webhooks\Database\CollationAudit;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\OwnerKeyDeclaration;
use Pushery\Webhooks\Server\Jobs\JobTimeoutBudget;
use Pushery\Webhooks\Support\Settings;
use RuntimeException;

/**
 * Checks that the database webhooks-for-laravel stores its tables in is one the package
 * can serve, before a migration fails deep inside a raw statement. It runs the same
 * capability guard the migrations do ({@see DatabaseRequirement}) and either confirms the
 * connection is supported or prints the one actionable message explaining what to fix.
 *
 * It then holds the CONFIGURATION against the SCHEMA — see {@see OwnerKeyDeclaration}. That
 * is the second question this command exists to answer at onboarding, because the answer is
 * otherwise delivered at the first customer click, months later, and in two of its three
 * forms it is not delivered at all: a wrongly declared owner key type refuses every owner
 * loudly, casts every UUID owner down to a small integer silently, and then refuses every
 * tenant its own deliveries in a way that reads like an authorization decision.
 *
 * A send-only host (WEBHOOKS_PLATFORM_ENABLED=false) stores nothing and can run on any
 * database, or none — this command still confirms whichever connection it is pointed at, and
 * a table that does not exist cannot contradict anything.
 *
 * Its third question is about the RECEIVING side, and it is here for the same reason as the
 * second: the answer is otherwise delivered by a producer, once, at the worst moment. A client
 * config with no 'secret', no 'jwks' and no 'verifier' cannot authenticate anything, and until
 * the first real delivery arrives nothing about the installation looks wrong — every page
 * renders, no check is red. The first symptom is a delivery that was refused, from a producer
 * that may not send it again.
 *
 * @internal
 */
final class PreflightCommand extends Command
{
    protected $signature = 'webhooks:preflight
        {--connection= : The database connection to check (defaults to the application default)}';

    protected $description = 'Check that the database the persistent layers store their tables in is supported, that the schema agrees with the configuration, and that every inbound client config can verify a delivery.';

    public function handle(Router $router): int
    {
        $option = $this->option('connection');

        // Neither half of this guard can change what an operator observes: flipping `&&` to `||`,
        // or comparing against a non-empty string, gives the same answer.
        // `DatabaseManager::connection()` opens with `enum_value($name) ?:
        // $this->getDefaultConnection()` (DatabaseManager.php:96), so an empty name already
        // resolves to the default, and the resolved connection reports the default name back, which
        // is what the closing sentence prints.
        //
        // It stays because it says the intent at the place the intent is formed. Reaching the same
        // answer through a falsy coercion three layers down in the framework is a fact about this
        // Laravel, not a decision this command made, and `--connection=` is exactly the shape a
        // shell hands over when a variable is unset.
        $name = is_string($option) && $option !== '' ? $option : null;

        try {
            DatabaseRequirement::ensure($name);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $connection = DB::connection($name);

        $contradictions = OwnerKeyDeclaration::contradictions($name);

        if ($contradictions !== []) {
            foreach ($contradictions as $message) {
                $this->components->error($message);
            }

            // A failure, not a warning. This command is what a host runs to be told the
            // install is sound before it carries traffic, and an installation whose owner key
            // type contradicts its own schema is not sound — it is one registration away from
            // a dead form and one dashboard away from refusing every tenant its own rows.
            return self::FAILURE;
        }

        // Only when the receiving layer is switched ON. With it off no route is registered,
        // so a faulty entry is inert -- and failing a preflight over configuration that
        // nothing reads would train a host to ignore this command's verdict, which is the
        // one thing it cannot afford. It becomes a failure the moment the layer is enabled.
        if (Config::boolean('webhooks.client.enabled', false)) {
            $faults = WebhookConfig::configurationFaults();

            if ($faults !== []) {
                foreach ($faults as $message) {
                    $this->components->error($message);
                }

                return self::FAILURE;
            }

            // A route that cannot carry traffic at all, and it is a FAILURE for the same reason
            // the owner-key contradiction above is: this command exists to say whether the
            // install is sound before it carries traffic, and a receiving route behind CSRF
            // answers every delivery 419 before the signature is verified. Nothing in this
            // package sees those requests, and most producers treat 4xx as permanent — so the
            // deliveries are not delayed, they are lost. Silent, and invisible from inside the
            // application: the route exists, the config entry is correct, and only the
            // producer's dashboard says otherwise.
            $forgeryFaults = ForgeryProtectedRoutes::faults($router);

            if ($forgeryFaults !== []) {
                foreach ($forgeryFaults as $message) {
                    $this->components->error($message);
                }

                return self::FAILURE;
            }

            // The other way the same binding breaks, and it is checked from the ROUTE side
            // because the config side cannot see it. A typo in the second argument of
            // Route::webhooks() leaves a route that exists, resolves and refuses every delivery
            // for ever -- and the config is not wrong, it simply has no entry of that name.
            //
            // A failure for the same reason the check above is one: the route cannot carry
            // traffic. Together with webhooks.client.expected this covers both directions of a
            // broken binding, and this half needs no setting, because mounting an endpoint is
            // already the statement that a source of that name is expected.
            $bindingFaults = UnboundReceivingRoutes::faults($router);

            if ($bindingFaults !== []) {
                foreach ($bindingFaults as $message) {
                    $this->components->error($message);
                }

                return self::FAILURE;
            }

            // Advisories rather than failures, for the reason the sync warning below gives: each
            // one describes a legal, working configuration, and failing a preflight over a legal
            // choice trains a host to ignore this command's verdict. What they must not do is
            // stay silent — a source with no replay boundary looks exactly like one with two,
            // because tolerance_seconds sits in the entry either way and the dedupe default is a
            // header the producer may never send.
            foreach (WebhookConfig::replayBoundaryAdvisories() as $message) {
                $this->components->warn($message);
            }
        }

        // The half-installed icon stack, named at the one moment a host is looking. The inputs
        // are the two things that actually decide it at render time: blade-icons publishes the
        // global svg() helper, and blade-heroicons is what registers the heroicon set the shipped
        // screens ask for. Neither is a dependency of this package, so both are asked about
        // rather than required.
        //
        // It joins the register below instead of getting an `if` of its own, and that is about
        // what can be proven. Both inputs are properties of the installation rather than of the
        // configuration, so on any one tree the branch is either always taken or never — and this
        // tree has neither package, which is the "never" half. A conditional no run can enter is a
        // line nobody has ever executed, and it would sit here looking tested.
        //
        // The second register below is config-driven, so it is entered on demand; filtering a
        // null out of one list costs nothing and leaves no unreachable line behind.
        $warnings = array_filter([
            new Settings()->iconPairingAdvisory(
                function_exists('svg'),
                class_exists(BladeHeroiconsServiceProvider::class),
            ),
            // Same register as the client-side advisories above, and for the same reason: a list
            // that survived as nothing is a host typo, not a state the package can refuse to boot
            // over. What it must not be is invisible — the config file still shows three codes.
            ...new Settings()->retryable4xxFaults(),
        ]);

        foreach ($warnings as $message) {
            $this->components->warn($message);
        }

        // A FAILURE, and the same class as the forgery-protected route above: not a legal
        // configuration with a cost, but one whose result is a duplicate webhook at a customer's
        // endpoint every time a delivery runs long. The two numbers belong to two different
        // owners — one is this package's arithmetic, the other the host's queue — so nothing but
        // a check that reads both can see it, and neither side looks wrong on its own.
        $timeoutFault = JobTimeoutBudget::fault();

        if ($timeoutFault !== null) {
            $this->components->error($timeoutFault);

            return self::FAILURE;
        }

        // A WARNING rather than a failure: sync is a legitimate choice while developing, and
        // failing a preflight over it would train a host to ignore this command's verdict.
        // What it must not do is stay silent, because the consequence is invisible from
        // inside the application — a released job on the sync connection is never picked up
        // again (SyncQueue never reads the released flag), so every retryable delivery
        // failure ends after ONE attempt and the retry schedule below it does nothing at all.
        // Read null-safe rather than through the typed getter. `webhooks.server.connection` is
        // `env('WEBHOOKS_SERVER_CONNECTION')` with no default, so on any host that has not set it
        // the key is present and null — and Config::string() throws on a present null, because its
        // default only covers a missing key. The typed getter would turn a preflight into a crash
        // on precisely the default installation it exists to reassure.
        $configured = Config::get('webhooks.server.connection');
        $serverConnection = is_string($configured) && $configured !== ''
            ? $configured
            : (is_string($default = Config::get('queue.default')) ? $default : 'sync');

        $driver = Config::get("queue.connections.{$serverConnection}.driver");

        if ($serverConnection === 'sync' || $driver === 'sync') {
            $this->components->warn(sprintf(
                'The server layer resolves to the [sync] queue connection, which has no worker: a delivery that '
                .'fails in a retryable way ends after one attempt instead of using its %d tries. Use a real queue '
                .'connection (database, redis, sqs) wherever deliveries matter.',
                is_int($tries = Config::get('webhooks.server.tries')) ? $tries : 3,
            ));
        }

        // The documentation names this command as one of the two things guarding the shipped
        // MySQL collation, and it read no collation anywhere — an operator following that page
        // after a restore or a DBA's ALTER got "preflight passed" over a schema that had lost
        // the property. A FAILURE rather than a warning, for the same reason the owner-key
        // contradiction above is one: the consequence is a delivery accepted, answered 200 and
        // dropped with nothing logged, and an installation that does that is not sound.
        $collation = CollationAudit::faults($connection);

        if ($collation !== []) {
            foreach ($collation as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Database preflight passed: the [%s] connection uses the [%s] driver, which is supported.',
            $connection->getName() ?? 'default',
            $connection->getDriverName(),
        ));

        return self::SUCCESS;
    }
}
