<?php

declare(strict_types=1);

namespace Webhooks\Core\Ssrf;

/**
 * Classifies a resolved IP address as safe (public) or blocked. Blocked covers
 * every private, loopback, link-local, ULA, carrier-grade-NAT, multicast,
 * documentation, benchmarking and cloud-metadata range for BOTH IPv4 and IPv6.
 *
 * The classification is done with an explicit CIDR list rather than PHP's
 * `FILTER_FLAG_NO_RES_RANGE`, whose IPv6 coverage is incomplete — the cloud
 * metadata address `fd00:ec2::254` (within `fc00::/7`) and link-local `fe80::/10`
 * would otherwise slip through. Every IPv6 form that embeds an IPv4 address —
 * IPv4-mapped (`::ffff:a.b.c.d`), IPv4-translated (`::ffff:0:a.b.c.d`) and the
 * deprecated IPv4-compatible form (`::a.b.c.d`) — is unwrapped first so a private
 * IPv4 cannot be smuggled in v6 clothing.
 *
 * @see https://www.rfc-editor.org/rfc/rfc5735 (IPv4 special-use)
 * @see https://www.rfc-editor.org/rfc/rfc6890 (special-purpose registries)
 *
 * @internal
 */
final class AddressClassifier
{
    /** @var list<string> */
    private const array BLOCKED_CIDRS = [
        // IPv4
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT (RFC 6598)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local (incl. 169.254.169.254 cloud metadata)
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1 (documentation)
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved (incl. 255.255.255.255 broadcast)
        // IPv6
        '::/128',             // unspecified
        '::1/128',            // loopback
        '64:ff9b::/96',       // NAT64
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        'fc00::/7',           // ULA (incl. fd00:ec2::254 cloud metadata)
        'fe80::/10',          // link-local
        // Site-local. Deprecated in favour of ULA (RFC 3879), but private BY INTENT and
        // still routed inside some networks — a webhook endpoint never legitimately lives
        // there, so it is blocked like every other private range.
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
    ];

    public function isBlocked(string $ip): bool
    {
        $ip = $this->unwrapMappedIp($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return array_any(self::BLOCKED_CIDRS, fn (string $cidr): bool => $this->inCidr($ip, $cidr));
    }

    /**
     * The 12-byte prefixes of every IPv6 form that merely embeds an IPv4 address in
     * its low 32 bits: IPv4-mapped (`::ffff:a.b.c.d`), IPv4-translated
     * (`::ffff:0:a.b.c.d`) and the deprecated IPv4-compatible form (`::a.b.c.d`, also
     * spelled `::7f00:1`). Each unwraps to its embedded IPv4 so a private address
     * cannot be smuggled past the filter dressed as IPv6.
     *
     * @var list<string>
     */
    private const array EMBEDDED_IPV4_PREFIXES = [
        "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff", // ::ffff:a.b.c.d  (mapped)
        "\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x00\x00", // ::ffff:0:a.b.c.d (translated)
        "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", // ::a.b.c.d       (compatible)
    ];

    /**
     * Unwrap an IPv6 literal that embeds an IPv4 address to that IPv4 form, so a
     * private IPv4 cannot be smuggled past the filter as IPv6. The unspecified (`::`)
     * and loopback (`::1`) addresses fall under the compatible prefix and unwrap to
     * `0.0.0.0` / `0.0.0.1`, both of which stay blocked by the IPv4 rules.
     */
    private function unwrapMappedIp(string $ip): string
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        foreach (self::EMBEDDED_IPV4_PREFIXES as $prefix) {
            if (str_starts_with($packed, $prefix)) {
                $unwrapped = inet_ntop(substr($packed, 12));

                return $unwrapped === false ? $ip : $unwrapped;
            }
        }

        return $ip;
    }

    private function inCidr(string $ip, string $cidr): bool
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
