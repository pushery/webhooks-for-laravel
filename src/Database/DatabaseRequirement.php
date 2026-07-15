<?php

declare(strict_types=1);

namespace Webhooks\Database;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Guards the package's persistent storage layer against a database engine it cannot
 * serve correctly. PostgreSQL is accepted outright; MySQL is accepted once it clears a
 * short capability check; everything else is refused with one clear, actionable message
 * instead of a cryptic failure deep inside a raw statement.
 *
 * This is the cross-engine successor to {@see PostgresRequirement}. Both exist during the
 * MySQL rollout: a migration whose table has a MySQL shape calls this guard, one that is
 * still PostgreSQL-only keeps calling PostgresRequirement. A default-deny architecture
 * test asserts every migration calls exactly one of the two.
 *
 * MySQL is held to real requirements, not a version label:
 *  - MariaDB is rejected. It reports itself as the `mysql` driver, but its JSON type is a
 *    text alias with no binary storage and it has neither multi-valued nor functional
 *    indexes, so the fan-out lookup and the dedupe index this package relies on do not hold.
 *  - The server must be MySQL 8.0.17+ (multi-valued JSON indexes landed there); in practice
 *    MySQL 8.4, the LTS.
 *  - Strict SQL mode must be on, or an over-long webhook body is silently truncated and its
 *    stored SHA-256 no longer matches the bytes.
 *  - PDO::MYSQL_ATTR_FOUND_ROWS must be off, or an upsert reports a matched row as affected
 *    and the inbound de-duplication dispatches a duplicate for every producer retry.
 *
 * Part of the supported public API: published migrations call it, so a host's own copy would
 * break if it moved — exactly like {@see PostgresRequirement}.
 */
final class DatabaseRequirement
{
    /**
     * The oldest MySQL that carries the multi-valued JSON index the fan-out lookup needs.
     */
    public const string MIN_MYSQL_VERSION = '8.0.17';

    /**
     * @throws RuntimeException when the resolved connection cannot serve the storage layer
     */
    public static function ensure(?string $connection = null): void
    {
        $resolved = DB::connection($connection);
        $name = $resolved->getName() ?? 'default';
        $driver = $resolved->getDriverName();

        if ($driver === 'pgsql') {
            return;
        }

        if ($driver !== 'mysql' || ! $resolved instanceof MySqlConnection) {
            throw self::unsupportedDriver($name, $driver);
        }

        if ($resolved->isMaria()) {
            throw self::mariadbRejected($name);
        }

        $reason = self::mysqlRejection(
            $resolved->getServerVersion(),
            self::sqlMode($resolved),
            self::foundRowsEnabled($resolved),
        );

        if ($reason !== null) {
            throw new RuntimeException(sprintf('The [%s] MySQL connection is not usable: %s', $name, $reason));
        }
    }

    /**
     * The reason a genuine (non-MariaDB) MySQL server cannot be used, or null when it can.
     * Pure by design — every branch is exercised from a unit test with crafted inputs, so
     * the version floor and the strict/FOUND_ROWS checks are covered without needing an old
     * or mis-configured server to connect to.
     */
    public static function mysqlRejection(string $version, string $sqlMode, bool $foundRows): ?string
    {
        if (version_compare($version, self::MIN_MYSQL_VERSION, '<')) {
            return sprintf(
                'it reports version %s, but MySQL %s+ is required for the multi-valued JSON index the '
                .'fan-out lookup uses. Upgrade to MySQL 8.4 (the LTS), or use PostgreSQL.',
                $version,
                self::MIN_MYSQL_VERSION,
            );
        }

        if (! str_contains($sqlMode, 'STRICT_TRANS_TABLES') && ! str_contains($sqlMode, 'STRICT_ALL_TABLES')) {
            return 'strict SQL mode is off (@@session.sql_mode carries neither STRICT_TRANS_TABLES nor '
                .'STRICT_ALL_TABLES). Without it an over-long webhook body is truncated on write and its '
                .'stored SHA-256 no longer matches. Enable strict mode — it is the MySQL 8.4 default.';
        }

        if ($foundRows) {
            return 'PDO::MYSQL_ATTR_FOUND_ROWS is enabled on the connection, which makes an upsert report a '
                .'matched row as affected — the inbound de-duplication would then dispatch a duplicate for '
                .'every producer retry. Remove that option from the connection.';
        }

        return null;
    }

    private static function unsupportedDriver(string $name, string $driver): RuntimeException
    {
        return new RuntimeException(sprintf(
            'webhooks-for-laravel needs a PostgreSQL or MySQL 8.4+ database for its persistent layers, but '
            .'the [%s] connection uses the [%s] driver. Point those layers at a supported connection '
            .'(WEBHOOKS_DB_CONNECTION), or run send-only (WEBHOOKS_PLATFORM_ENABLED=false) with no database at all.',
            $name,
            $driver,
        ));
    }

    private static function mariadbRejected(string $name): RuntimeException
    {
        return new RuntimeException(sprintf(
            'The [%s] connection is MariaDB, which is not supported. It reports itself as the MySQL driver, but '
            .'its JSON type is a text alias with no binary storage and it has neither multi-valued nor functional '
            .'indexes, so the fan-out and de-duplication guarantees this package relies on do not hold. '
            .'Use MySQL 8.4+ or PostgreSQL.',
            $name,
        ));
    }

    private static function sqlMode(MySqlConnection $connection): string
    {
        /** @var object{sql_mode?: string}|null $row */
        $row = $connection->selectOne('SELECT @@session.sql_mode AS sql_mode');

        return $row->sql_mode ?? '';
    }

    private static function foundRowsEnabled(MySqlConnection $connection): bool
    {
        /** @var array<int|string, mixed> $options */
        $options = $connection->getConfig('options') ?? [];

        return (bool) ($options[PDO::MYSQL_ATTR_FOUND_ROWS] ?? false);
    }
}
