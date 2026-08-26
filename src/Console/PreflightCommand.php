<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pushery\Webhooks\Client\WebhookConfig;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\OwnerKeyDeclaration;
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

    public function handle(): int
    {
        $option = $this->option('connection');

        // ⚠️ Both halves of this guard are EQUIVALENT mutants and are reported every run —
        // flipping `&&` to `||`, or comparing against a non-empty string, changes nothing an
        // operator can observe. Measured, not argued: `DatabaseManager::connection()` opens with
        // `enum_value($name) ?: $this->getDefaultConnection()` (DatabaseManager.php:96), so an
        // empty name already resolves to the default, and the resolved connection reports the
        // DEFAULT name back — which is what the closing sentence prints.
        //
        // It stays because it says the intent at the place the intent is formed. Reaching the
        // same answer through a falsy-coercion three layers down in the framework is a fact
        // about this Laravel, not a decision this command made, and `--connection=` is exactly
        // the shape a shell hands over when a variable is unset.
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
        }

        $this->components->info(sprintf(
            'Database preflight passed: the [%s] connection uses the [%s] driver, which is supported.',
            $connection->getName() ?? 'default',
            $connection->getDriverName(),
        ));

        return self::SUCCESS;
    }
}
