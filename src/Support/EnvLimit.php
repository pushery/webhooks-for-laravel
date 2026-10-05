<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

/**
 * Reads a numeric limit from the environment, held to the smallest value that is still a limit.
 *
 * `env()` hands back a string, and the casts that turn it into a number accept too much: `(int)`
 * makes 0 of `abc`, of `0.5` and of `.5`, and keeps `-3`, so a typo in a timeout or a rate limit
 * would quietly switch it off or make it absurd. Here only a whole number counts. One below the
 * floor takes the shipped default, and so does anything that is no whole number, an empty value
 * and an unset variable.
 *
 * Zero is a value of its own for a few keys, where the package documents it as switching that
 * limit off. Only those pass `$zeroSwitchesOff`, and then exactly `0` does that, never a value
 * that would merely cast to it.
 */
final class EnvLimit
{
    public static function ceiling(mixed $value, int $default, int $floor = 1, bool $zeroSwitchesOff = false): int
    {
        $number = self::wholeNumber($value);

        if ($zeroSwitchesOff && $number === 0) {
            return 0;
        }

        return $number !== null && $number >= $floor ? $number : $default;
    }

    /**
     * The same reading for a limit that ships without a value, where null means no limit.
     */
    public static function optionalCeiling(mixed $value, ?int $default, int $floor = 1): ?int
    {
        $number = self::wholeNumber($value);

        return $number !== null && $number >= $floor ? $number : $default;
    }

    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/\A\s*-?\d+\s*\z/', $value) !== 1) {
            return null;
        }

        return (int) trim($value);
    }
}
