<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Reads the configured value of a per-minute abuse brake, and spends from one.
 *
 * A positive whole number is the allowance, written as an int or as the digit string env()
 * returns for one. Null and a number of zero or less switch the brake off, which is how the
 * configuration spells that. Anything else is not read as off: a value that cannot be read
 * falls back to the shipped default, because the one thing a brake must not do on a typo is
 * stop braking.
 *
 * @internal
 */
final class PerMinuteBrake
{
    public static function read(mixed $value, int $default): ?int
    {
        if (is_string($value) && preg_match('/\A\s*-?\d+\s*\z/', $value) === 1) {
            $value = (int) trim($value);
        }

        if ($value === null || (is_int($value) && $value <= 0)) {
            return null;
        }

        return is_int($value) ? $value : $default;
    }

    /**
     * Spend one unit of each of these allowances, all or none, and return the key of the first one that is spent, or
     * null when every one had room.
     *
     * Counted first and judged by the count each charge returns. Asked first and counted after, requests that arrive
     * together all read the last free unit and all spend it, and the number of workers is the only bound. A refusal
     * gives back every unit it counted, the refusing one included, so a refused request spends nothing.
     *
     * @param  list<string>  $keys
     */
    public static function spend(array $keys, int $max, int $window): ?string
    {
        $charged = [];

        foreach ($keys as $key) {
            $charged[] = $key;

            if (RateLimiter::hit($key, $window) > $max) {
                self::giveBack($charged);

                return $key;
            }
        }

        return null;
    }

    /**
     * Give back the unit a refused spend counted under each of these keys.
     *
     * The limiter puts a count that falls back to one with the decay it is handed, so the decay is the time left in
     * the window rather than its length: handed the length, the count would outlive the window. A count that falls to
     * nothing was opened by the refused request itself, and its window goes with it, so a refusal on one allowance does
     * not start the minute of another.
     *
     * @param  list<string>  $keys
     */
    private static function giveBack(array $keys): void
    {
        foreach ($keys as $key) {
            if (RateLimiter::decrement($key, max(1, RateLimiter::availableIn($key))) <= 0) {
                RateLimiter::clear($key);
            }
        }
    }
}
