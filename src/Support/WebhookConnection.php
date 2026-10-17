<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pushery\Webhooks\Database\Dialect\Dialect;

/**
 * Resolves the database connection the package stores its tables on.
 *
 * By default that is the application's own default connection. A host may instead point
 * webhooks.database.connection at a dedicated connection — the common case being a MySQL
 * application that keeps webhooks' PostgreSQL-shaped tables on a PostgreSQL side-car. Every
 * model, migration and raw query in the package resolves through here, so the whole package
 * follows one connection and never silently splits across two.
 *
 * Part of the public API, because the migrations this package publishes call it. Once published, a
 * migration runs in the host's own namespace, and a host that analyzes its migrations reads every call
 * there into a class the package marks internal as an error it cannot fix without editing a published
 * file.
 */
final class WebhookConnection
{
    /**
     * The configured connection name, or null for the application default.
     */
    public static function name(): ?string
    {
        // Config::get, not Config::string: an unset WEBHOOKS_DB_CONNECTION leaves the key present
        // with a null value, and Config::string throws on a present-but-null key rather than
        // returning the default. A null or empty value both mean "the application default".
        $connection = Config::get('webhooks.database.connection');

        if (is_string($connection) && $connection !== '') {
            return $connection;
        }

        return null;
    }

    /**
     * The resolved connection for the package's raw queries — the one place bare DB:: facade
     * calls are replaced, so a query can never accidentally run against the app's default while
     * the tables live on the side-car.
     */
    public static function db(): ConnectionInterface
    {
        return DB::connection(self::name());
    }

    /**
     * The SQL dialect of the resolved webhook connection — the engine the package's tables
     * physically live on (the side-car, when one is configured), never the application default.
     *
     * Runtime SQL must render for this dialect, not for an argument-less dialect lookup (which
     * resolves the app-default connection's driver): in the documented side-car topology the two
     * are different engines, so that would render, say, MySQL SQL against a PostgreSQL side-car.
     * The migrations already key their DDL on this connection, so the read/write dialect must match.
     */
    public static function dialect(): Dialect
    {
        return Dialect::for(self::name());
    }
}
