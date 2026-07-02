<?php

declare(strict_types=1);

namespace Webhooks\Contracts;

/**
 * Resolves a hostname to the IP addresses a delivery would actually connect to.
 * Isolated behind an interface so the SSRF guard can be tested deterministically
 * and so hosts can be re-resolved at delivery time to defeat DNS rebinding.
 */
interface HostResolver
{
    /**
     * @return list<string> resolved IP addresses (empty when the host does not resolve)
     */
    public function resolve(string $host): array;
}
