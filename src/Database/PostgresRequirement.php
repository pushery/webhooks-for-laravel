<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database;

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
     * The oldest PostgreSQL this package is built and tested against.
     *
     * It was stated in three places and enforced in none. `installation.md`, the README and this
     * class's own docblock all say "PostgreSQL 13+", and both migration guards used to `return` the
     * moment they saw the pgsql driver — no version read at all. Meanwhile the MySQL half checks
     * its floor, the SQL mode, MariaDB and FOUND_ROWS, each with a message naming what it found and
     * what to do.
     *
     * So the two engines were treated unequally in the direction that matters: a host on MySQL
     * 8.0 was told exactly what was wrong, and a host on PostgreSQL 12 got a raw SQL error from
     * the middle of a migration — about a syntax it has never heard of, on a requirement it was
     * never told about.
     *
     * Thirteen is deliberate, and the obvious repair is to raise it to fourteen. The shipped
     * dashboard migrations call `date_bin`, which PostgreSQL grew in 14 — three files say so, one
     * of them in a comment. A reader who finds that and nothing else concludes the floor is a
     * version too low and fixes it, which would refuse a server this package deliberately supports.
     *
     * What they would have missed is one method further down that same migration:
     * `bucketExpression()` reads `server_version_num` and returns an epoch-floor expression
     * below 140000, yielding the identical whole-hour boundary. The 13 path exists, it is
     * written, and raising this constant would delete it without touching it.
     *
     * So the rule for changing this number: the floor is the OLDEST server every shipped path
     * still has a branch for — not the newest syntax anything uses.
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
            self::ensureVersion($resolved->getName() ?? 'default', $resolved->getServerVersion());

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
     * Public and taking the version as an argument, so both guards read one floor and a test can
     * drive it without a server of that vintage — which is the only way this arm can be proven
     * at all, since the suite runs on a current PostgreSQL.
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
            .'PostgreSQL %s+ — the oldest it is built and tested against. Its tables use jsonb, '
            .'GIN indexes and declarative range partitioning, so an older server fails somewhere '
            .'inside a migration instead of here. Upgrade the server, or use MySQL 8.4+ and '
            .'re-publish the migrations to get that schema.',
            $name,
            $version,
            self::MIN_VERSION,
        ));
    }
}
