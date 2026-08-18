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
 * **Every decision this class makes is in a pure function; the live lookup carries none.**
 * That is deliberate, and it is why `merge()`, `ipv6From()` and `normalizeRecords()` are
 * static: an unqualified `dns_get_record()` is unreachable from a test, so a branch left
 * beside it is a branch nothing can exercise. The AAAA half used to be
 * `@dns_get_record($host, DNS_AAAA) ?: []` inline, and neither direction of that `?:` was
 * ever reached — no offline host has an AAAA record, and the two hosts the suite uses answer
 * with an empty ARRAY, never with `false`. What it hid was not academic: negate the ternary
 * and a dual-stack destination is classified on its IPv4 addresses alone while the delivery
 * can still connect over IPv6.
 *
 * @internal
 */
final class SystemHostResolver implements HostResolver
{
    /**
     * How the AAAA records are fetched. Injectable so the WIRING — that the answer reaches
     * {@see self::merge()} at all — is provable without a live lookup; the default is the
     * system resolver and is what the container always builds.
     *
     * @var callable(string): mixed
     */
    private $aaaaLookup;

    /**
     * @param  (callable(string): mixed)|null  $aaaaLookup
     */
    public function __construct(?callable $aaaaLookup = null)
    {
        $this->aaaaLookup = $aaaaLookup ?? static fn (string $host): mixed => @dns_get_record($host, DNS_AAAA);
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        return self::merge($ips, self::normalizeRecords(($this->aaaaLookup)($host)));
    }

    /**
     * What a raw AAAA answer means as a record set.
     *
     * `dns_get_record()` answers `false` on a resolution error, and {@see self::merge()}
     * declares `array` — so without this conversion a failed lookup would raise a TypeError
     * AFTER the guard had decided to resolve, turning "fail closed" into a 500. An entry that
     * is not itself an array is dropped for the same reason {@see self::ipv6From()} drops a
     * record with no usable address: a malformed answer must never reach the classifier as
     * something it will try to read.
     *
     * Pure and static so both directions are directly testable — the live lookup beside it
     * cannot be.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function normalizeRecords(mixed $answer): array
    {
        if (! is_array($answer)) {
            return [];
        }

        return array_values(array_filter($answer, is_array(...)));
    }

    /**
     * The address list one lookup answers with: every A record, then every AAAA record,
     * de-duplicated and renumbered so the caller always receives a plain list. BOTH
     * families are kept — dropping either would leave half the destination unvetted, and
     * the guard must classify every address a delivery could actually connect to. Pure
     * and static so the merge is directly testable without a live DNS lookup.
     *
     * @param  list<string>  $ipv4
     * @param  list<array<array-key, mixed>>  $aaaa
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
     * @param  list<array<array-key, mixed>>  $records
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
