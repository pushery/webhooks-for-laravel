<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use Pushery\Webhooks\Core\Ssrf\PinnedEndpoint;

/**
 * The local addresses a guarded request leaves from, one per address family.
 *
 * A receiver that admits one published address in its firewall sees a request from exactly that address. The request
 * is bound to the address of the family it connects in, and it connects only in a family an address is given for: the
 * pinned addresses of the other family are left out of the pin, and a destination with none of the given families is
 * refused before any connection rather than sent along the default route. A local address the host does not have
 * makes the connection fail when curl binds to it.
 */
final readonly class EgressAddresses
{
    /**
     * @throws InvalidArgumentException when neither address is given, or one is not an address of its family
     */
    public function __construct(
        /** The address an IPv4 connection leaves from, or null where the request may not connect over IPv4. */
        public ?string $ipv4 = null,
        /** The address an IPv6 connection leaves from, or null where the request may not connect over IPv6. */
        public ?string $ipv6 = null,
    ) {
        if ($ipv4 === null && $ipv6 === null) {
            throw new InvalidArgumentException('Name at least one local address a request leaves from.');
        }

        if ($ipv4 !== null && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("The IPv4 address a request leaves from is not one: '{$ipv4}'.");
        }

        if ($ipv6 !== null && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException("The IPv6 address a request leaves from is not one: '{$ipv6}'.");
        }
    }

    /**
     * The request options that bind a request to this endpoint to the address of the family it connects in, and pin it
     * to the endpoint's addresses of that family.
     *
     * The family is the first one of the endpoint's an address is given for: of its pinned addresses in their order, of
     * the address in its URL, or, for an allowlisted host the guard left unpinned, IPv4 before IPv6. It goes to Guzzle
     * as `force_ip_resolve`, which Guzzle 7 and 8 both turn into `CURLOPT_IPRESOLVE`; Guzzle 8 refuses
     * `CURLOPT_IPRESOLVE` among the curl options themselves.
     *
     * @return array{force_ip_resolve: 'v4'|'v6', curl: array<int, mixed>}
     *
     * @throws ConnectionException when the endpoint has no address in a family an address is given for
     */
    public function requestOptionsFor(PinnedEndpoint $endpoint): array
    {
        foreach ($this->familiesOf($endpoint) as $family) {
            $source = $family === 4 ? $this->ipv4 : $this->ipv6;

            if ($source === null) {
                continue;
            }

            // `host!` names an address rather than an interface, so curl never looks the value up as a device.
            $curl = [CURLOPT_INTERFACE => 'host!'.$source];

            $resolve = new PinnedEndpoint(
                url: $endpoint->url,
                host: $endpoint->host,
                port: $endpoint->port,
                scheme: $endpoint->scheme,
                ips: array_values(array_filter($endpoint->ips, static fn (string $ip): bool => self::familyOf($ip) === $family)),
            )->curlResolveEntries();

            if ($resolve !== []) {
                $curl[CURLOPT_RESOLVE] = $resolve;
            }

            return ['force_ip_resolve' => $family === 4 ? 'v4' : 'v6', 'curl' => $curl];
        }

        throw new ConnectionException(sprintf(
            'The destination %s has no address in a family this request may leave from, so it is not sent along the default route.',
            $endpoint->host,
        ));
    }

    /**
     * The families the endpoint can be reached in, in the order a connection would try them.
     *
     * @return list<4|6>
     */
    private function familiesOf(PinnedEndpoint $endpoint): array
    {
        if ($endpoint->ips !== []) {
            return array_values(array_unique(array_map(self::familyOf(...), $endpoint->ips)));
        }

        $literal = trim($endpoint->host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [self::familyOf($literal)];
        }

        return [4, 6];
    }

    /** @return 4|6 */
    private static function familyOf(string $address): int
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : 4;
    }
}
