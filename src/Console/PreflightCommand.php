<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webhooks\Database\DatabaseRequirement;

/**
 * Checks that the database webhooks-for-laravel stores its tables in is one the package
 * can serve, before a migration fails deep inside a raw statement. It runs the same
 * capability guard the migrations do ({@see DatabaseRequirement}) and either confirms the
 * connection is supported or prints the one actionable message explaining what to fix.
 *
 * A send-only host (WEBHOOKS_PLATFORM_ENABLED=false) stores nothing and can run on any
 * database, or none — this command still confirms whichever connection it is pointed at.
 *
 * @internal
 */
final class PreflightCommand extends Command
{
    protected $signature = 'webhooks:preflight
        {--connection= : The database connection to check (defaults to the application default)}';

    protected $description = 'Check that the database the persistent layers store their tables in is supported.';

    public function handle(): int
    {
        $option = $this->option('connection');
        $name = is_string($option) && $option !== '' ? $option : null;

        try {
            DatabaseRequirement::ensure($name);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $connection = DB::connection($name);

        $this->components->info(sprintf(
            'Database preflight passed: the [%s] connection uses the [%s] driver, which is supported.',
            $connection->getName() ?? 'default',
            $connection->getDriverName(),
        ));

        return self::SUCCESS;
    }
}
