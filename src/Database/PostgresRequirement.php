<?php

declare(strict_types=1);

namespace Webhooks\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guards the package's PostgreSQL-only storage layer. The delivery log relies on
 * jsonb, GIN indexes, partial indexes and declarative range partitioning — none of
 * which exist on MySQL or SQLite — so running the migrations against any other
 * driver would otherwise fail with a cryptic SQL syntax error deep inside a raw
 * statement. This turns that into one clear, actionable message up front.
 *
 * On Laravel Cloud this means provisioning a Neon (PostgreSQL) database rather than
 * the MySQL option.
 */
final class PostgresRequirement
{
    /**
     * @throws RuntimeException when the resolved connection is not PostgreSQL
     */
    public static function ensure(?string $connection = null): void
    {
        $resolved = DB::connection($connection);
        $driver = $resolved->getDriverName();

        if ($driver === 'pgsql') {
            return;
        }

        throw new RuntimeException(sprintf(
            'webhooks-for-laravel requires a PostgreSQL database, but the [%s] connection '
            .'uses the [%s] driver. The delivery log uses jsonb, GIN indexes and declarative '
            .'range partitioning, which only PostgreSQL provides. On Laravel Cloud, provision '
            .'a Neon (PostgreSQL) database rather than MySQL.',
            $resolved->getName(),
            $driver,
        ));
    }
}
