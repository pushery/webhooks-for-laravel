<?php

declare(strict_types=1);

namespace Webhooks\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

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

    public static function sql(DateTimeInterface $moment): string
    {
        return self::utc($moment)->format(self::SQL_FORMAT);
    }

    public static function utc(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'));
    }
}
