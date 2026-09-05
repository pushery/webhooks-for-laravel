<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Payload;

/**
 * Makes a decoded payload storable: strips NUL bytes, and names the floats JSON cannot write.
 *
 * PostgreSQL's jsonb type — unlike json or text — categorically cannot hold the
 * escape sequence json_encode() emits for a NUL byte: the insert fails outright with
 * "unsupported Unicode escape sequence ... cannot be converted to text". A NUL byte
 * in a payload string is never intentional (a truncated string from a C library, a
 * mangled CSV cell, a binary blob a caller believed was text), but it is entirely
 * real, and letting it reach the column turns a webhook into a hard failure: inbound
 * the receiver 500s on every retry until the producer gives up and the event is lost;
 * outbound the fan-out throws mid-request.
 *
 * The second half is the same failure with a different cause. `json_decode` turns a literal
 * larger than a double can hold -- `1e400` -- into `INF`, and `json_encode` cannot write `INF`
 * back, so it throws. That happens AFTER the signature has been checked, in the storing path, so
 * a valid delivery from a producer with a very large number in it was answered 500 on every
 * attempt and never stored. It is not an attack, it is a currency or measurement source with a
 * value beyond the double range.
 *
 * Written as its own string -- `INF`, `-INF`, `NAN` -- rather than as null, because null cannot
 * be told apart from a field that was absent. The string says what was there.
 *
 * Scrubbing is deliberately lossy-but-valid, and applies to keys as well as values.
 * It runs at the edge — once, before the payload is stored AND before it is signed —
 * so the logged copy and the delivered bytes stay identical and a redelivery
 * reproduces them exactly. Inbound, the exact received bytes survive alongside it (the
 * stored raw body and its SHA-256), so nothing is destroyed: only the queryable jsonb
 * view of the payload is cleaned.
 *
 * @internal
 */
final class PayloadSanitizer
{
    /**
     * Recursively remove every NUL byte, and replace every non-finite float with its own name.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function scrub(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $clean[is_string($key) ? self::scrubString($key) : $key] = match (true) {
                is_array($value) => self::scrub($value),
                is_string($value) => self::scrubString($value),
                // `is_float` first: `is_finite()` is declared for float and an int would be
                // coerced, which is a conversion this has no business making.
                is_float($value) && ! is_finite($value) => self::nameOf($value),
                default => $value,
            };
        }

        return $clean;
    }

    private static function scrubString(string $value): string
    {
        return str_replace("\0", '', $value);
    }

    /**
     * The three values a float can hold that JSON has no literal for.
     *
     * PHP's own cast produces these spellings, so this is the name the value already answers to
     * rather than one invented here.
     *
     * The caller reaches this only for a float that is not finite, so past the NAN branch the
     * value is one of the two infinities. The comparison is a sign test with no boundary to get
     * wrong: any finite bound in place of the zero, and either strictness, reads the same.
     */
    private static function nameOf(float $value): string
    {
        return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
    }
}
