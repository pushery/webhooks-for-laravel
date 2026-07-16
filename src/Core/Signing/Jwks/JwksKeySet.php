<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing\Jwks;

use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;
use Webhooks\Core\Http\HttpTransport;
use Webhooks\Core\Http\TransportOptions;
use Webhooks\Core\Signing\SecretSet;
use Webhooks\Core\Ssrf\SsrfGuard;

/**
 * Fetches, parses and caches a provider's JWKS (JSON Web Key Set) of Ed25519 public
 * keys so a rotating provider's keys can back {@see Ed25519Scheme}
 * verification without redistributing anything. Only OKP/Ed25519 keys (`kty=OKP`,
 * `crv=Ed25519`) are read; the base64url `x` parameter is the raw 32-byte public key.
 * Keys are indexed by their `kid`.
 *
 * The fetch is routed through the shared {@see SsrfGuard} + {@see HttpTransport}, so a
 * JWKS URL that resolves to a private, loopback or cloud-metadata address is refused
 * exactly like any other egress — a JWKS endpoint is attacker-influenced input and
 * must never be fetched with a raw HTTP client. The parsed key set is cached for the
 * configured TTL, so verification does not make a network call per delivery.
 *
 * A resolved key is returned as a base64-encoded 32-byte public key inside a
 * {@see SecretSet}, the exact token {@see Ed25519Scheme::verify()}
 * consumes.
 *
 * @internal
 */
final readonly class JwksKeySet
{
    public function __construct(
        private SsrfGuard $guard,
        private HttpTransport $transport,
        private Cache $cache,
    ) {}

    /**
     * The provider's Ed25519 public keys, base64-encoded and indexed by `kid`, served
     * from cache for the TTL after the first fetch.
     *
     * @return array<int|string, string>
     */
    public function keys(string $url, int $ttlSeconds): array
    {
        $cacheKey = 'webhooks:jwks:'.hash('sha256', $url);

        /** @var array<int|string, string> $cached */
        $cached = $this->cache->get($cacheKey, []);

        if ($cached !== []) {
            return $cached;
        }

        $keys = $this->fetch($url);

        // Cache ONLY a non-empty key set. fetch() returns an empty array for any response that is
        // not a well-formed JWKS document — a provider's maintenance page, a 5xx with a body, a
        // transient parse failure — and Cache::remember would pin that empty result for the full
        // TTL, rejecting every JWKS-verified webhook for up to an hour. Not caching an empty fetch
        // lets the very next inbound request retry, so a momentary upstream blip is not an outage.
        if ($keys !== []) {
            $this->cache->put($cacheKey, $keys, $ttlSeconds);
        }

        return $keys;
    }

    /**
     * The public key(s) to verify against, as a {@see SecretSet}. With a `kid` the set
     * holds exactly that key; without one it holds up to two keys (current + previous),
     * covering the rotation window a `v1a` header — which carries no key id — needs to
     * try. Throws when no matching key is available.
     */
    public function secretSet(string $url, int $ttlSeconds, ?string $kid = null): SecretSet
    {
        $keys = $this->keys($url, $ttlSeconds);

        if ($kid !== null) {
            return isset($keys[$kid])
                ? SecretSet::fromCurrent($keys[$kid])
                : throw new InvalidArgumentException("The JWKS at [{$url}] has no Ed25519 key with kid [{$kid}].");
        }

        $values = array_values($keys);

        return match (count($values)) {
            0 => throw new InvalidArgumentException("The JWKS at [{$url}] exposes no usable Ed25519 (OKP) keys."),
            1 => SecretSet::fromCurrent($values[0]),
            default => SecretSet::rotating($values[0], $values[1]),
        };
    }

    /**
     * @return array<int|string, string>
     */
    private function fetch(string $url): array
    {
        $endpoint = $this->guard->resolveAndPin($url);

        $response = $this->transport->send(
            $endpoint,
            '',
            ['Accept' => 'application/json'],
            new TransportOptions(verb: 'get'),
        );

        return $this->parse($response->body);
    }

    /**
     * @return array<int|string, string>
     */
    private function parse(string $body): array
    {
        $document = json_decode($body, true);

        if (! is_array($document) || ! is_array($document['keys'] ?? null)) {
            return [];
        }

        $keys = [];
        $index = 0;

        foreach ($document['keys'] as $key) {
            if (! is_array($key)) {
                continue;
            }
            if (($key['kty'] ?? null) !== 'OKP') {
                continue;
            }
            if (($key['crv'] ?? null) !== 'Ed25519') {
                continue;
            }
            $x = $key['x'] ?? null;

            if (! is_string($x)) {
                continue;
            }

            $raw = base64_decode(strtr($x, '-_', '+/'), false);

            if (strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            $kid = is_string($key['kid'] ?? null) && $key['kid'] !== '' ? $key['kid'] : (string) $index;
            $keys[$kid] = base64_encode($raw);
            $index++;
        }

        return $keys;
    }
}
