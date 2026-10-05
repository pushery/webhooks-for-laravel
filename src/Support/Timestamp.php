<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Pushery\Webhooks\Database\Dialect\Dialect;

/**
 * Renders a moment as an UNAMBIGUOUS SQL timestamp literal: the same instant in UTC,
 * carrying its offset — "2026-07-12 10:30:00.000000+00:00".
 *
 * On PostgreSQL every timestamp column in this package is timestamptz, and PostgreSQL resolves a
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
     * rather than loudly. On MySQL the columns are UTC-naive DATETIME(6), and MySQL converts an
     * offset-bearing literal into the session time zone before it compares (8.0.19+), so on a
     * session that is not UTC every comparison slides by that offset: an equality finds nothing
     * and a range loses the rows at one edge, with no error. That choice was written out as the
     * same ternary in five places; a dialect difference belongs in exactly one, which is what
     * the Dialect enum exists for.
     */
    public static function forDialect(Dialect $dialect, DateTimeInterface $moment): string
    {
        return $dialect === Dialect::MySql ? self::mysql($moment) : self::sql($moment);
    }

    /**
     * The instant a timestamp read back from the engine stands for: the mirror of forDialect().
     *
     * PostgreSQL hands a timestamptz back with its offset, so the string names the instant.
     * MySQL hands DATETIME(6) back naive, and it is UTC because UTC is all this package writes
     * there. Parsed without a zone, that string resolves against PHP's default, which is
     * app.timezone, and the instant lands off by the host's own offset: a plausible value,
     * one or two hours beside the truth, and nothing goes red.
     */
    public static function read(Dialect $dialect, string $value): CarbonImmutable
    {
        if ($dialect === Dialect::MySql) {
            return CarbonImmutable::parse($value, 'UTC');
        }

        return CarbonImmutable::parse($value);
    }

    public static function utc(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'));
    }
}
