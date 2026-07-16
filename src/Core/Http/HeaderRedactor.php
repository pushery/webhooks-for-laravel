<?php

declare(strict_types=1);

namespace Webhooks\Core\Http;

/**
 * Masks the credential-bearing headers before they are persisted to the inbound call log —
 * so a stored request never carries a bearer token, a signing secret or a session cookie in
 * clear text. `Authorization` and `Cookie` are ALWAYS masked; a host adds more names through
 * its redact list. Both the live receive path and the spatie backfill import redact through
 * here, so the two can never drift on which headers are secret.
 *
 * @internal
 */
final class HeaderRedactor
{
    /**
     * Header names that carry credentials and are masked regardless of any configuration.
     *
     * @var list<string>
     */
    public const array ALWAYS = ['authorization', 'cookie'];

    /**
     * Replace the value of every credential-bearing header with a fixed marker, comparing
     * names case-insensitively. Non-secret headers pass through untouched. Accepts any array
     * key so it can defend an untrusted map (a header blob decoded from a backfill source),
     * normalising each name to a string before matching.
     *
     * @param  array<array-key, mixed>  $headers
     * @param  list<string>  $extra  additional header names to mask (a host's redact list)
     * @return array<array-key, mixed>
     */
    public static function mask(array $headers, array $extra = []): array
    {
        $redact = array_map(strtolower(...), [...self::ALWAYS, ...$extra]);

        $masked = [];

        foreach ($headers as $name => $value) {
            $masked[$name] = in_array(strtolower((string) $name), $redact, true) ? '[redacted]' : $value;
        }

        return $masked;
    }
}
