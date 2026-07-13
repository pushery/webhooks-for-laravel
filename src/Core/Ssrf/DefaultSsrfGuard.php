<?php

declare(strict_types=1);

namespace Webhooks\Core\Ssrf;

use Webhooks\Core\Http\Exceptions\BlockedDestination;

/**
 * The default SSRF guard. Refuses non-HTTP(S) schemes, plaintext HTTP when HTTPS
 * is required, blocked hosts, unresolvable hosts, and any host resolving to a
 * private/reserved address (via {@see AddressClassifier}). Returns the vetted IPs
 * pinned so the transport connects only to exactly those addresses.
 *
 * @internal
 */
final readonly class DefaultSsrfGuard implements SsrfGuard
{
    /**
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $blockedHosts
     */
    public function __construct(
        private HostResolver $resolver,
        private AddressClassifier $classifier,
        private bool $httpsOnly = true,
        private bool $blockPrivateNetworks = true,
        private array $allowedHosts = [],
        private array $blockedHosts = [],
    ) {}

    public function resolveAndPin(string $url): PinnedEndpoint
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw BlockedDestination::malformed($url);
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw BlockedDestination::unsupportedScheme($scheme);
        }

        if ($scheme === 'http' && $this->httpsOnly) {
            throw BlockedDestination::insecureScheme();
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'http' ? 80 : 443);

        if ($this->matchesHostList($host, $this->blockedHosts)) {
            throw BlockedDestination::blockedHost($host);
        }

        // An explicitly allowed host bypasses resolution and pinning: the operator
        // opts a known internal endpoint back in and accepts that responsibility.
        if ($this->matchesHostList($host, $this->allowedHosts) || ! $this->blockPrivateNetworks) {
            return new PinnedEndpoint($url, $host, $port, $scheme, []);
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw BlockedDestination::unresolvable($host);
        }

        foreach ($ips as $ip) {
            if ($this->classifier->isBlocked($ip)) {
                throw BlockedDestination::privateAddress($host, $ip);
            }
        }

        return new PinnedEndpoint($url, $host, $port, $scheme, $ips);
    }

    /**
     * @param  list<string>  $list
     */
    private function matchesHostList(string $host, array $list): bool
    {
        return array_any($list, fn (string $entry): bool => strtolower($entry) === $host);
    }
}
