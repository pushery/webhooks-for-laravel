<?php

declare(strict_types=1);

namespace Webhooks\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guards a PostgreSQL-shaped migration set. That profile relies on jsonb, GIN indexes,
 * partial indexes and declarative range partitioning — none of which exist on MySQL or
 * SQLite — so running it against another driver would otherwise fail with a cryptic SQL
 * syntax error deep inside a raw statement. This turns that into one clear message up front.
 *
 * The package itself runs on PostgreSQL 13+ OR MySQL 8.4+; this guard only rejects the
 * PostgreSQL migration profile on a non-PostgreSQL driver. A host on MySQL re-publishes the
 * migrations to get the MySQL schema (see {@see DatabaseRequirement}). On Laravel Cloud
 * either the first-party MySQL 8.4 database or Neon (PostgreSQL) works — run the migration
 * set that matches the engine you choose.
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
            'This migration builds a PostgreSQL-only table (jsonb, GIN indexes, declarative range '
            .'partitioning), but the [%s] connection uses the [%s] driver. The package itself now also '
            .'runs on MySQL 8.4+ — re-publish the migrations (php artisan vendor:publish --tag=webhooks-'
            .'migrations, --tag=webhooks-client-migrations, …) to get the MySQL schema, point the '
            .'persistent layers at a PostgreSQL connection (WEBHOOKS_DB_CONNECTION), or run send-only '
            .'(WEBHOOKS_PLATFORM_ENABLED=false) with no database at all.',
            $resolved->getName(),
            $driver,
        ));
    }
}
