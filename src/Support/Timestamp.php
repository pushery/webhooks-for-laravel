<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Pushery\Webhooks\Database\Dialect\Dialect;

/**
 * Renders a moment as an UNAMBIGUOUS SQL timestamp literal: the same instant in UTC,
 * carrying its offset — "2026-07-12 10:30:00.000000+00:00".
 *
 * Every timestamp column in this package is timestamptz, and PostgreSQL resolves a
 * NAIVE literal ("2026-07-12 10:30:00") against the SESSION time zone — a database
 * setting, not an application one. A naive binding therefore means a different instant
 * depending on where it runs, and under a non-UTC application timezone the DST
 * fall-back hour maps two instants an hour apart onto the identical literal. Carrying
 * the offset removes both ambiguities: the literal IS the instant, whatever either
 * clock is set to.
 *
 * Use it for every timestamp this package binds into SQL — raw statements, query
 * builder comparisons and partition bounds alike.
 *
 * @internal
 */
final class Timestamp
{
    /**
     * The literal format: microsecond precision plus an explicit offset. PostgreSQL
     * parses it into a timestamptz without consulting the session zone.
     */
    public const string SQL_FORMAT = 'Y-m-d H:i:s.uP';

    /**
     * The Eloquent date format for a model whose timestamps are timestamptz: the same
     * explicit offset, at the second precision Eloquent has always written, so an
     * equality lookup against a stored created_at still matches exactly.
     */
    public const string ELOQUENT_FORMAT = 'Y-m-d H:i:sP';

    /**
     * The literal format for MySQL DATETIME(6), which is timezone-naive: UTC microseconds
     * with NO offset. The value carries the instant only because it is always UTC — MySQL
     * cannot store the offset a timestamptz literal carries, and TIMESTAMP would re-resolve
     * it against the session zone and top out in 2038.
     */
    public const string MYSQL_SQL_FORMAT = 'Y-m-d H:i:s.u';

    public static function sql(DateTimeInterface $moment): string
    {
        return self::utc($moment)->format(self::SQL_FORMAT);
    }

    /**
     * The instant as a MySQL DATETIME(6) literal: the same moment in UTC, naive.
     */
    public static function mysql(DateTimeInterface $moment): string
    {
        return self::utc($moment)->format(self::MYSQL_SQL_FORMAT);
    }

    /**
     * The instant rendered for the engine it will be compared against.
     *
     * The two literals are not interchangeable, and picking the wrong one fails SILENTLY
     * rather than loudly: an offset-bearing PostgreSQL literal matches ZERO naive rows under
     * MySQL, so a query returns an empty set instead of an error. That choice was written out
     * as the same ternary in five places; a dialect difference belongs in exactly one, which
     * is what the Dialect enum exists for.
     */
    public static function forDialect(Dialect $dialect, DateTimeInterface $moment): string
    {
        return $dialect === Dialect::MySql ? self::mysql($moment) : self::sql($moment);
    }

    public static function utc(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'));
    }
}
