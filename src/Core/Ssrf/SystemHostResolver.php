<?php

declare(strict_types=1);

namespace Webhooks\Core\Ssrf;

/**
 * The production resolver: resolves BOTH A (IPv4) and AAAA (IPv6) records via the
 * system resolver, so an IPv6-only host is vetted rather than silently failing an
 * IPv4-only lookup. An IP literal resolves to itself. DNS warnings on a lookup
 * that returns nothing are suppressed — the empty result is what the caller acts
 * on (the SSRF guard fails closed on it).
 *
 * @internal
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        /** @var list<array<string, mixed>> $aaaa */
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];

        return self::merge($ips, $aaaa);
    }

    /**
     * The address list one lookup answers with: every A record, then every AAAA record,
     * de-duplicated and renumbered so the caller always receives a plain list. BOTH
     * families are kept — dropping either would leave half the destination unvetted, and
     * the guard must classify every address a delivery could actually connect to. Pure
     * and static so the merge is directly testable without a live DNS lookup.
     *
     * @param  list<string>  $ipv4
     * @param  list<array<string, mixed>>  $aaaa
     * @return list<string>
     */
    public static function merge(array $ipv4, array $aaaa): array
    {
        return array_values(array_unique([...$ipv4, ...self::ipv6From($aaaa)]));
    }

    /**
     * The IPv6 addresses carried by a set of AAAA records. A record that carries no
     * usable `ipv6` string is dropped rather than trusted: a malformed answer must
     * never reach the address classifier as a non-address. Pure and static so the
     * record handling is directly testable without a live DNS lookup.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    public static function ipv6From(array $records): array
    {
        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
