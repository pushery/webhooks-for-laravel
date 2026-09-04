<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Read a `YYYY-MM-DD` filter bound the way a browser actually sends one.
 *
 * Every caller binds this to a public Livewire property, which means the value is whatever
 * the browser sent — including the half-typed dates `wire:model.live` delivers between
 * keystrokes. Three separate things go wrong there, and each is silent in a different way,
 * which is why this is one shared reader rather than a line of parsing per panel.
 *
 * @internal
 */
final class CalendarDay
{
    /**
     * The start of the day a bound names, or null when the value is absent or not a date.
     *
     * Null rather than an exception: a filter that threw on a half-typed date would turn
     * typing into an error page. An unreadable value is simply not a bound.
     */
    public static function start(string $value): ?CarbonInterface
    {
        if ($value === '') {
            return null;
        }

        // The shape is checked BEFORE Carbon sees it, because Carbon THROWS on a string it
        // cannot read rather than returning false — measured on '2026-0', which is what
        // wire:model.live sends while a reader is still typing the year ("A four digit year
        // could not be found"). /D so a trailing newline cannot slip past the anchor.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = Date::createFromFormat('!Y-m-d', $value);

        // And the round-trip rejects what Carbon reads TOO willingly. Every well-shaped
        // string parses — measured: '2026-13-45' becomes 2027-02-14, '2026-02-30' becomes
        // 2026-03-02, '0000-00-00' becomes -0001-11-30 — so the parse alone would answer a
        // question the reader did not ask. Comparing the result back against the input is
        // what turns "parsed" into "meant".
        if (! $date instanceof CarbonInterface || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * The exclusive upper bound for a day named as an inclusive one.
     *
     * A reader asking for "up to the 20th" means the whole 20th. The half-open form says
     * that without arguing about how many decimals a timestamp has.
     */
    public static function endExclusive(string $value): ?CarbonInterface
    {
        return self::start($value)?->addDay();
    }
}
