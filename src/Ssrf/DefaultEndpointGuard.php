<?php

declare(strict_types=1);

namespace Webhooks\Ssrf;

use Webhooks\Contracts\EndpointGuard;
use Webhooks\Contracts\HostResolver;
use Webhooks\Exceptions\BlockedEndpointException;

/**
 * Default SSRF-hardened endpoint guard. Refuses non-HTTP(S) schemes, plaintext
 * HTTP when HTTPS is required, and any host that resolves to a private, loopback,
 * link-local, unique-local, carrier-grade-NAT, multicast or cloud-metadata
 * address. Because it resolves the host itself, calling it again immediately
 * before delivery defeats DNS rebinding.
 */
final readonly class DefaultEndpointGuard implements EndpointGuard
{
    /**
     * Ranges the platform's private/reserved filter does not already exclude:
     * carrier-grade NAT (RFC 6598, used for internal services in some cloud
     * networks) and IPv4/IPv6 multicast.
     *
     * @var list<string>
     */
    private const array BLOCKED_CIDRS = ['100.64.0.0/10', '224.0.0.0/4', 'ff00::/8'];

    /**
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $blockedHosts
     */
    public function __construct(
        private HostResolver $resolver,
        private bool $httpsOnly = true,
        private bool $blockPrivateNetworks = true,
        private array $allowedHosts = [],
        private array $blockedHosts = [],
    ) {}

    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw BlockedEndpointException::malformed($url);
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw BlockedEndpointException::unsupportedScheme($scheme);
        }

        if ($scheme === 'http' && $this->httpsOnly) {
            throw BlockedEndpointException::insecureScheme();
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($this->matchesHostList($host, $this->blockedHosts)) {
            throw BlockedEndpointException::blockedHost($host);
        }

        // An explicitly allowed host bypasses the private-network check so an
        // operator can opt a known internal endpoint back in. It is not resolved,
        // so nothing is returned to pin — the operator accepts that responsibility.
        if ($this->matchesHostList($host, $this->allowedHosts)) {
            return [];
        }

        if (! $this->blockPrivateNetworks) {
            return [];
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw BlockedEndpointException::unresolvable($host);
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedAddress($ip)) {
                throw BlockedEndpointException::privateAddress($host, $ip);
            }
        }

        // Every resolved address is public — return them so the caller can pin the
        // connection to exactly these, leaving no room for a re-resolution to differ.
        return $ips;
    }

    /**
     * @param  list<string>  $list
     */
    private function matchesHostList(string $host, array $list): bool
    {
        return array_any($list, fn (string $entry): bool => strtolower($entry) === $host);
    }

    private function isBlockedAddress(string $ip): bool
    {
        $ip = $this->normalizeMappedIp($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return array_any(self::BLOCKED_CIDRS, fn (string $cidr): bool => $this->ipInCidr($ip, $cidr));
    }

    /**
     * Unwrap an IPv4-mapped IPv6 address (::ffff:a.b.c.d) to its IPv4 form so it
     * cannot be used to smuggle a private IPv4 address past the filter.
     */
    private function normalizeMappedIp(string $ip): string
    {
        $packed = inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16
            && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $unwrapped = inet_ntop(substr($packed, 12));

            return $unwrapped === false ? $ip : $unwrapped;
        }

        return $ip;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $prefix;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);

        return (ord($ipBin[$wholeBytes]) & ord($mask)) === (ord($subnetBin[$wholeBytes]) & ord($mask));
    }
}
