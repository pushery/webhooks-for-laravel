<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database;

use Illuminate\Database\ConnectionInterface;
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
     * The oldest PostgreSQL this package supports, and the floor ensureVersion() enforces.
     *
     * A promise about which servers it runs on, not a report on which ones were tested: every
     * shipped path is written to run on this version and on every later one. Both migration
     * guards read it, so an older server is refused before the first migration, with a message
     * that names the version found and the way out, as the MySQL half does for its own floor,
     * the SQL mode, MariaDB and FOUND_ROWS.
     *
     * Thirteen is deliberate, although the shipped dashboard migrations call `date_bin`, which
     * PostgreSQL added in version 14. The same migration's `bucketExpression()` reads
     * `server_version_num` and returns an epoch-floor expression below 140000, which yields the
     * identical whole-hour boundary, so raising this constant would refuse a server that path
     * still serves.
     *
     * The rule for changing this number: the floor is the OLDEST server every shipped path
     * still has a branch for, not the newest syntax anything uses.
     */
    public const string MIN_VERSION = '13';

    /**
     * @throws RuntimeException when the resolved connection is not PostgreSQL, or is one this
     *                          package does not support
     */
    public static function ensure(?string $connection = null): void
    {
        $resolved = DB::connection($connection);
        $driver = $resolved->getDriverName();

        if ($driver === 'pgsql') {
            $name = $resolved->getName() ?? 'default';

            self::ensureVersion($name, $resolved->getServerVersion());
            self::ensureIdentity($name, $resolved);

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

    /**
     * Refuse a PostgreSQL older than the floor, in the same shape the MySQL half uses: name the
     * version found, the one required, and the way out.
     *
     * Public and taking the version as an argument, so both guards read one floor and a caller
     * can check a version without a server of that vintage.
     *
     * @throws RuntimeException when the server is older than {@see self::MIN_VERSION}
     */
    public static function ensureVersion(string $name, string $version): void
    {
        if (version_compare($version, self::MIN_VERSION, '>=')) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The [%s] PostgreSQL connection reports version %s, but this package requires '
            .'PostgreSQL %s+, the oldest it supports. Its tables use jsonb, '
            .'GIN indexes and declarative range partitioning, so an older server fails somewhere '
            .'inside a migration instead of here. Upgrade the server, or use MySQL 8.4+ and '
            .'re-publish the migrations to get that schema.',
            $name,
            $version,
            self::MIN_VERSION,
        ));
    }

    /**
     * Refuse a server that speaks PostgreSQL's protocol without being PostgreSQL.
     *
     * Such a server passes the version check above: CockroachDB reports a
     * `server_version` of 18.0.0, YugabyteDB 2.25 one of 15.2-YB-2.25.0.0-b0. Its `version()`
     * banner says what it is, the way MariaDB's says what MariaDB is, so the banner is read
     * rather than a list of products, and an engine nobody listed is refused the same way.
     *
     * @throws RuntimeException when the server's banner is not PostgreSQL's
     */
    public static function ensureIdentity(string $name, ConnectionInterface $connection): void
    {
        $row = (array) $connection->selectOne('select version() as banner');
        $banner = $row['banner'] ?? null;

        if (is_string($banner) && self::isPostgresBanner($banner)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The [%s] connection uses the pgsql driver, but its server reports itself as [%s], which is '
            .'not PostgreSQL. A server that only speaks its protocol fails somewhere inside a migration '
            .'instead of here: the tables use declarative range partitioning, materialized views and GIN '
            .'indexes. Use PostgreSQL %s+, or MySQL 8.4+ and re-publish the migrations to get that schema.',
            $name,
            is_string($banner) ? mb_strimwidth($banner, 0, 80, '…') : 'no version at all',
            self::MIN_VERSION,
        ));
    }

    /**
     * Whether a `version()` banner is PostgreSQL's own: `PostgreSQL ` and a plain release number,
     * a development or pre-release suffix included, followed by the build details or nothing.
     * A compatible engine either names itself first or appends its own version to the number.
     */
    public static function isPostgresBanner(string $banner): bool
    {
        return preg_match('/^PostgreSQL \d+(?:\.\d+)*(?:devel|(?:alpha|beta|rc)\d*)?(?:[\s,]|$)/', $banner) === 1;
    }
}
