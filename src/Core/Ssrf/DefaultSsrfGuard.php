<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Ssrf;

use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable;

/**
 * The default SSRF guard. Refuses non-HTTP(S) schemes, plaintext HTTP when HTTPS
 * is required, blocked hosts, and any host resolving to a
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

        // The trailing root dot is cut here, not in matchesHostList(), and the place matters.
        // `blocked.example.` and `blocked.example` are the same name to every resolver and two
        // different strings to an exact comparison, so an operator's blocklist entry was
        // bypassed by one character. Cutting it at the comparison alone would leave the dot on
        // $host, which travels into PinnedEndpoint::curlResolveEntries() — and then the
        // blocklist and the pin would be talking about different names.
        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');

        // A host of nothing but dots trims away to nothing. parse_url() accepts it, and an
        // empty host is not a destination — it belongs with the other malformed URLs rather
        // than reaching a resolver.
        if ($host === '') {
            throw BlockedDestination::malformed($url);
        }
        $port = $parts['port'] ?? ($scheme === 'http' ? 80 : 443);

        // And the same name has to reach the TRANSPORT, or the pin describes a destination the
        // request never asks for: PinnedEndpoint names $host in its CURLOPT_RESOLVE entry while
        // the request goes to ->url, so a URL still carrying the dot is resolved again at
        // connect time — the TOCTOU rebind window the pin exists to close. Rebuilt only when
        // the two disagree, so an ordinary URL reaches curl exactly as its caller wrote it.
        $url = $this->canonicalize($url, $parts, $host);

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
            // RETRYABLE, not final. PHP's resolver answers `false` for a name that does not
            // exist and for a lookup that merely failed — NXDOMAIN, SERVFAIL, a resolver
            // timeout, a few seconds of lost network — and only the first is permanent. See
            // HostUnresolvable for the arithmetic; the short version is that treating the
            // transient three as final loses a delivery each AND feeds the circuit breaker,
            // while treating the permanent one as transient costs a few cheap lookups.
            throw HostUnresolvable::for($host);
        }

        foreach ($ips as $ip) {
            if ($this->classifier->isBlocked($ip)) {
                throw BlockedDestination::privateAddress($host, $ip);
            }
        }

        return new PinnedEndpoint($url, $host, $port, $scheme, $ips);
    }

    /**
     * The caller's URL with its host replaced by the canonical form, or unchanged when it
     * already carries it.
     *
     * @param  array<string, int|string>  $parts
     */
    private function canonicalize(string $url, array $parts, string $host): string
    {
        // An IPv6 literal is the one host that is not written as it is stored: parse_url() keeps
        // its brackets, PinnedEndpoint's entry does not, and reassembling without them would
        // produce a URL no parser accepts.
        $authority = str_contains($host, ':') ? "[{$host}]" : $host;

        if (($parts['host'] ?? null) === $authority) {
            return $url;
        }

        $userinfo = '';

        if (isset($parts['user'])) {
            $userinfo = $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        }

        // The port is carried only where the caller wrote one. Adding the default would change
        // the Host header of every request this branch touches.
        // The cast answers the declared shape of parse_url()'s result, not the value: this
        // branch is only reached for a URL that already parsed with a scheme, so it is a string
        // by then. strtolower() beside it is NOT redundant -- a scheme is case-insensitive and
        // the caller may have written it in capitals.
        return strtolower((string) $parts['scheme']).'://'
            .$userinfo
            .$authority
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * @param  list<string>  $list
     */
    private function matchesHostList(string $host, array $list): bool
    {
        // The same cut on the entries, so an operator may write the name either way. $host has
        // already been canonicalized by the caller; this is about what the CONFIG is allowed
        // to contain.
        return array_any($list, fn (string $entry): bool => rtrim(strtolower($entry), '.') === $host);
    }
}
