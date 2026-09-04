<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Ssrf;

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
     * The CURLOPT_RESOLVE entries pinning host:port to the vetted IPs, or an empty array for the
     * two destinations that get no entry: an allowlisted host, which is intentionally unpinned,
     * and a host that is an IP literal, which has no name to re-resolve.
     *
     * So an empty result is not the same question as `isPinned()`. That method answers whether
     * the guard vetted addresses at all, and for a literal it answers true while nothing is
     * handed to curl — correctly, because the address in the URL is the address that was
     * classified. Read this method when the question is what the transport is told; read
     * isPinned() when the question is whether the destination was vetted.
     *
     * @return list<string>
     */
    public function curlResolveEntries(): array
    {
        if ($this->ips === []) {
            return [];
        }

        // An IP literal gets no entry, and this is about delivery rather than tidiness. curl
        // parses an entry as `host:port:addr`, and its host half does not accept an IPv6 literal
        // in any spelling — measured against curl 8.7.1, both `2606:…:443:2606:…` and the
        // bracketed `[2606:…]:443:[2606:…]` end in `curl: (49) Couldn't parse CURLOPT_RESOLVE
        // entry`. That is a hard failure, not a warning: the transfer never starts, so an
        // endpoint whose URL is written as an IPv6 literal could not be delivered to at all.
        // (The address half does accept IPv6, bracketed or bare — only the host half refuses
        // it.)
        //
        // Nothing is given up by leaving it out. Pinning exists to close the gap between the
        // name the guard vetted and the name curl resolves at connect time; a literal has no
        // such gap — there is no lookup, and the address in the URL is the address that was
        // classified. The IPv4 literal takes the same exit for the same reason.
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return ["{$this->host}:{$this->port}:".implode(',', $this->ips)];
    }
}
