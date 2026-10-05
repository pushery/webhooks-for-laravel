<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Str;

/**
 * Reads an environment switch whose off position loosens a protection.
 *
 * `env()` returns a variable that is set but empty as "", and `filter_var()` reads "" as false.
 * For a switch that opens a path that is the safe direction: the path stays closed. For a switch
 * that protects something it is the unsafe one. A line such as `WEBHOOKS_HTTPS_ONLY=`, copied from
 * an `.env` template or created empty by a deployment tool, would let a plaintext endpoint through
 * without a word.
 *
 * Here an empty or blank value counts as not set and takes the declared default. Every other
 * value reads as the other switches read it, and a value that is no boolean at all takes the
 * default as well.
 */
final class EnvFlag
{
    public static function protection(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || (is_string($value) && Str::trim($value) === '')) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
