<?php

declare(strict_types=1);

namespace Webhooks\Signing;

/**
 * Verifies a {@see WebhookSigner} signature header on the receiving side. Ship
 * this to consumers (or reimplement it in any language): parse the header, reject
 * timestamps outside the tolerance, then constant-time-compare the HMAC of
 * "{timestamp}.{rawBody}" against each provided `v1=` value.
 */
final class SignatureVerifier
{
    public static function verify(string $header, string $body, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        ['timestamp' => $timestamp, 'signatures' => $signatures] = self::parse($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $now ??= time();

        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return array_any($signatures, static fn (string $signature): bool => hash_equals($expected, $signature));
    }

    /**
     * @return array{timestamp: int|null, signatures: list<string>}
     */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}
