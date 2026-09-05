<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * Masks the credential-bearing headers before they are persisted to the inbound call log —
 * so a stored request never carries a bearer token, a signing secret or a session cookie in
 * clear text. The names in {@see self::ALWAYS} are masked regardless of configuration; a host
 * adds more through its redact list. Both the live receive path and the backlog import redact through
 * here, so the two can never drift on which headers are secret.
 *
 * @internal
 */
final class HeaderRedactor
{
    /**
     * Header names that carry credentials and are masked regardless of any configuration.
     *
     * The three `php-auth-*` names are not headers a producer sends, which is why they were
     * missing for so long. Symfony's ServerBag decodes an `Authorization: Basic` line and puts
     * the two halves back as `PHP_AUTH_USER` and `PHP_AUTH_PW`, and they reach the header bag as
     * ordinary names from there; `PHP_AUTH_DIGEST` is the same construction for digest auth.
     *
     * A stored blob therefore masked `authorization` and carried the same password in clear text
     * one key further down. That is worse than masking nothing: the redacted line beside it makes
     * the blob read as safe. `proxy-authorization` is an ordinary credential header that was
     * simply never on the list.
     *
     * @var list<string>
     */
    public const array ALWAYS = [
        'authorization',
        'cookie',
        'proxy-authorization',
        'php-auth-user',
        'php-auth-pw',
        'php-auth-digest',
    ];

    /**
     * Replace the value of every credential-bearing header with a fixed marker, comparing
     * names case-insensitively. Non-secret headers pass through untouched. Accepts any array
     * key so it can defend an untrusted map (a header blob decoded from a backfill source),
     * normalizing each name to a string before matching.
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
