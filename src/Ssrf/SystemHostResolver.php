<?php

declare(strict_types=1);

namespace Webhooks\Ssrf;

use Webhooks\Contracts\HostResolver;

/**
 * Resolves hosts through the system resolver (honouring /etc/hosts), which is
 * what the HTTP client will use when it connects. IP literals are returned
 * as-is. Resolution is limited to IPv4 A records; IPv6 literals are still
 * validated, and applications needing AAAA resolution can bind a custom
 * {@see HostResolver}.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // gethostbynamel() emits a warning for a host that does not resolve;
        // swallow it (a non-resolving host is a valid, expected outcome here).
        set_error_handler(static fn (): bool => true);

        try {
            $ips = gethostbynamel($host);
        } finally {
            restore_error_handler();
        }

        return $ips === false ? [] : $ips;
    }
}
