<?php

declare(strict_types=1);

namespace Webhooks\Core\Ssrf;

/**
 * A vetted, connection-pinned destination: the original URL plus the exact IP
 * addresses it resolved to. The transport pins curl to these via CURLOPT_RESOLVE
 * so a TOCTOU DNS-rebind cannot swap the address between the guard's check and the
 * connect. An operator-allowlisted host is returned UNPINNED (no ips) — the
 * operator has accepted responsibility for that host.
 */
final readonly class PinnedEndpoint
{
    /**
     * @param  list<string>  $ips
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $scheme,
        public array $ips,
    ) {}

    public function isPinned(): bool
    {
        return $this->ips !== [];
    }

    /**
     * The CURLOPT_RESOLVE entries pinning host:port to the vetted IPs, or an empty
     * array when the destination is intentionally unpinned (an allowlisted host).
     *
     * @return list<string>
     */
    public function curlResolveEntries(): array
    {
        if ($this->ips === []) {
            return [];
        }

        return ["{$this->host}:{$this->port}:".implode(',', $this->ips)];
    }
}
