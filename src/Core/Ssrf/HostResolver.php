<?php

declare(strict_types=1);

namespace Webhooks\Core\Ssrf;

/**
 * Resolves a hostname to its IP addresses. Abstracted so the SSRF guard can be
 * tested against a fake resolver, and so the real one resolves BOTH A and AAAA
 * records — an IPv6-only host must not slip past an IPv4-only lookup.
 */
interface HostResolver
{
    /**
     * @return list<string> every resolved IPv4 and IPv6 address (empty if none)
     */
    public function resolve(string $host): array;
}
