<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

/**
 * Reads the configured value of a per-minute abuse brake.
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
}
