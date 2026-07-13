<?php

declare(strict_types=1);

namespace Webhooks\Core\Http\Exceptions;

use RuntimeException;

/**
 * Thrown when an outgoing webhook destination is refused by the SSRF guard —
 * a malformed URL, a disallowed scheme, plaintext HTTP where HTTPS is required,
 * a blocked host, an unresolvable host, or a host resolving to a private,
 * loopback, link-local, ULA, CGNAT, multicast or cloud-metadata address.
 *
 * It is {@see NonRetryable}: a blocked destination is a final failure, never
 * retried, because it can only be attacker influence or misconfiguration.
 */
final class BlockedDestination extends RuntimeException implements NonRetryable
{
    public static function malformed(string $url): self
    {
        return new self("The webhook URL is malformed: [{$url}].");
    }

    public static function unsupportedScheme(string $scheme): self
    {
        return new self("The webhook URL scheme [{$scheme}] is not supported; use http or https.");
    }

    public static function insecureScheme(): self
    {
        return new self('The webhook URL must use https (plaintext http is disabled).');
    }

    public static function blockedHost(string $host): self
    {
        return new self("The webhook host [{$host}] is blocked by policy.");
    }

    public static function unresolvable(string $host): self
    {
        return new self("The webhook host [{$host}] could not be resolved to any IP address.");
    }

    public static function privateAddress(string $host, string $ip): self
    {
        return new self("The webhook host [{$host}] resolves to a private or reserved address [{$ip}].");
    }
}
