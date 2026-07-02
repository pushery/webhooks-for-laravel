<?php

declare(strict_types=1);

namespace Webhooks\Exceptions;

use RuntimeException;

final class BlockedEndpointException extends RuntimeException
{
    public static function malformed(string $url): self
    {
        return new self("The webhook URL is malformed: {$url}");
    }

    public static function unsupportedScheme(string $scheme): self
    {
        return new self("The webhook URL scheme is not supported: {$scheme}");
    }

    public static function insecureScheme(): self
    {
        return new self('The webhook URL must use HTTPS.');
    }

    public static function blockedHost(string $host): self
    {
        return new self("The webhook host is not allowed: {$host}");
    }

    public static function unresolvable(string $host): self
    {
        return new self("The webhook host could not be resolved: {$host}");
    }

    public static function privateAddress(string $host, string $ip): self
    {
        return new self("The webhook host {$host} resolves to a private or reserved address: {$ip}");
    }
}
