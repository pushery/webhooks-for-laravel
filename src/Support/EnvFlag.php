<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Str;

/**
 * Reads an environment switch, an empty value counting as not set.
 *
 * `env()` returns a variable that is set but empty as "", and `filter_var()` reads "" as false.
 * A line such as `WEBHOOKS_HTTPS_ONLY=`, copied from an `.env` template or created empty by a
 * deployment tool, would let a plaintext endpoint through without a word, and
 * `WEBHOOKS_SERVER_ENABLED=` would stop outbound delivery without one. Every boolean switch of
 * the shipped config reads through here, so a published copy that turns a switch on keeps it on
 * while the variable stands empty.
 *
 * Here an empty or blank value counts as not set and takes the declared default. Every other
 * value reads as `filter_var()` reads it, and a value that is no boolean at all takes the
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
