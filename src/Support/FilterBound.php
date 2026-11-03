<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Pushery\Webhooks\Dashboard\DashboardTimezone;

/**
 * Read a delivery filter bound: a day, `YYYY-MM-DD`, or a moment, `YYYY-MM-DDTHH:MM`.
 *
 * A day is what the plain tree's date fields send and what a link written before the datetime
 * picker carries, and it means the whole day. A moment is what WireKit's datetime picker sends, and
 * it means that minute. Either is read in the zone the list shows its times in, so a bound names
 * the clock the reader sees beside each row.
 *
 * Every caller binds this to a public Livewire property, which means the value is whatever the
 * browser sent, including the half-typed dates `wire:model.live` delivers between keystrokes.
 * Anything that is not one of the two shapes, or names a day or a time that does not exist, is no
 * bound at all rather than an error: a filter that threw on a half-typed date would turn typing
 * into an error page.
 *
 * @internal
 */
final class FilterBound
{
    private const string DAY = '!Y-m-d';

    private const string MOMENT = '!Y-m-d\\TH:i';

    /**
     * The first instant a bound names, or null when the value is absent or names none.
     */
    public static function start(string $value): ?CarbonInterface
    {
        $format = self::format($value);

        if ($format === null) {
            return null;
        }

        // format() has read the value in one of the two shapes already, so parse() meets nothing it
        // cannot read, and in a zone that skips the time it moves past the gap as the picker does.
        return Date::parse($value, self::zone());
    }

    /**
     * The first instant after a bound, so an upper bound includes the whole day or the whole minute
     * it names: "until the 20th" is the whole 20th, "until 17:30" the whole of that minute. The
     * half-open form says it without arguing about how many decimals a timestamp has.
     */
    public static function endExclusive(string $value): ?CarbonInterface
    {
        $start = self::start($value);

        if (! $start instanceof CarbonInterface) {
            return null;
        }

        return self::format($value) === self::DAY ? $start->addDay() : $start->addMinute();
    }

    /**
     * The moment the datetime picker shows for a bound. The picker shows a moment and no day, so a
     * day is shown as its first minute, or as its last for an upper bound, which reads the same
     * whole day. A moment is shown as it is, anything else as no value.
     */
    public static function shown(string $value, bool $end): string
    {
        return match (self::format($value)) {
            self::DAY => $value.($end ? 'T23:59' : 'T00:00'),
            self::MOMENT => $value,
            default => '',
        };
    }

    /**
     * The zone the delivery lists show their times in: the dashboard's display zone where one is
     * configured, the application's otherwise.
     */
    public static function zone(): string
    {
        return DashboardTimezone::current() ?? date_default_timezone_get();
    }

    /**
     * The format a value is written in, or null when it is neither shape or names no real day or
     * time.
     */
    private static function format(string $value): ?string
    {
        // The shape is checked before Carbon sees the value, because Carbon throws on a string it
        // cannot read rather than returning false: '2026-0', which wire:model.live sends while a
        // reader is still typing the year. /D so a trailing newline cannot slip past the anchor.
        $format = match (true) {
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 => self::DAY,
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value) === 1 => self::MOMENT,
            default => null,
        };

        if ($format === null) {
            return null;
        }

        // And the round trip rejects what Carbon reads too willingly: '2026-13-45' becomes
        // 2027-02-14, '2026-02-30' becomes 2026-03-02, '24:00' becomes the next day. It runs in UTC,
        // which has no clock change, so a time a zone skips in spring is still the time the reader
        // picked; start() reads it in the zone, which moves it past the gap.
        $parsed = Date::createFromFormat($format, $value, 'UTC');

        if (! $parsed instanceof CarbonInterface || $parsed->format(ltrim($format, '!')) !== $value) {
            return null;
        }

        return $format;
    }
}
