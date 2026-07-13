<?php

declare(strict_types=1);

namespace Webhooks\Core\Payload;

/**
 * Strips NUL bytes out of a payload so it can be stored.
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
     * Recursively remove every NUL byte from an array's string keys and string values.
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
                default => $value,
            };
        }

        return $clean;
    }

    private static function scrubString(string $value): string
    {
        return str_replace("\0", '', $value);
    }
}
