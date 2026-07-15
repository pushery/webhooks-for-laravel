<?php

declare(strict_types=1);

namespace Webhooks\Client;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Webhooks\Client\Dedupe\DedupeKeyResolver;
use Webhooks\Client\Jobs\ProcessWebhookJob;
use Webhooks\Client\Models\WebhookCall;
use Webhooks\Client\Profiles\ProcessEverythingWebhookProfile;
use Webhooks\Client\Profiles\WebhookProfile;
use Webhooks\Client\Responses\DefaultRespondsTo;
use Webhooks\Client\Responses\RespondsToWebhook;
use Webhooks\Client\Verification\InboundVerifier;
use Webhooks\Core\Signing\AcceptsSignatureHeaders;
use Webhooks\Core\Signing\Ed25519Scheme;
use Webhooks\Core\Signing\Jwks\JwksKeySet;
use Webhooks\Core\Signing\SecretSet;
use Webhooks\Core\Signing\SignatureHeaders;
use Webhooks\Core\Signing\SignatureScheme;
use Webhooks\Core\Signing\StandardWebhooksScheme;

/**
 * A typed, resolved view of a single webhooks.client.configs entry, selected by
 * name. Every accessor returns a fully-typed object with the entry's value or the
 * documented default, so the pipeline never juggles raw mixed config. scheme =>
 * 'auto' resolves to the Server layer's default {@see StandardWebhooksScheme}, so
 * an app receives its own deliveries with no extra plumbing.
 */
final class WebhookConfig
{
    /**
     * @param  class-string<SignatureScheme>  $schemeClass
     * @param  array{id?: string, timestamp?: string, signature?: string}  $explicitHeaders
     * @param  class-string<WebhookProfile>  $profileClass
     * @param  class-string<RespondsToWebhook>  $responseClass
     * @param  class-string<WebhookCall>  $modelClass
     * @param  class-string<ProcessWebhookJob>|array<string, class-string<ProcessWebhookJob>>|null  $process
     * @param  list<string>  $redact
     * @param  '*'|list<string>  $storeHeaders
     * @param  array{enabled: bool, threshold: int, disk: string}  $largePayload
     * @param  array{max_attempts: int, decay_seconds: int}|null  $rateLimit
     */
    public function __construct(
        public string $name,
        private readonly string $secret,
        private readonly ?string $previousSecret,
        private readonly string $schemeClass,
        private readonly ?string $verifierClass,
        private readonly string $idHeader,
        private readonly string $timestampHeader,
        private readonly string $signatureHeader,
        private readonly array $explicitHeaders,
        private readonly int $tolerance,
        private readonly int $invalidStatus,
        private readonly string $profileClass,
        private readonly string $responseClass,
        private readonly string $modelClass,
        private string|array|null $process,
        private readonly array $redact,
        private readonly string|array $storeHeaders,
        private readonly string $dedupe,
        private readonly ?string $dedupeId,
        private readonly ?string $jwksUrl,
        private readonly int $jwksCacheTtl,
        private readonly ?string $jwksKid,
        private readonly array $largePayload,
        private readonly ?array $rateLimit,
    ) {}

    /**
     * Resolve the config entry whose 'name' matches, throwing when none is defined.
     */
    public static function forName(string $name): self
    {
        foreach (Config::array('webhooks.client.configs', []) as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return self::fromEntry($name, $entry);
            }
        }

        throw new InvalidArgumentException("No webhook client config named [{$name}] is defined in webhooks.client.configs.");
    }

    /**
     * The verification key material. A 'jwks' url resolves the producer's Ed25519
     * public keys through the SSRF-guarded, cached {@see JwksKeySet}; otherwise the
     * static configured secret (a shared HMAC secret, or a base64 Ed25519 public key)
     * is used, with the previous secret added during a rotation window.
     */
    public function secrets(): SecretSet
    {
        if ($this->jwksUrl !== null) {
            return app(JwksKeySet::class)->secretSet($this->jwksUrl, $this->jwksCacheTtl, $this->jwksKid);
        }

        return $this->previousSecret === null
            ? SecretSet::fromCurrent($this->secret)
            : SecretSet::rotating($this->secret, $this->previousSecret);
    }

    public function scheme(): SignatureScheme
    {
        if ($this->schemeClass === StandardWebhooksScheme::class) {
            return new StandardWebhooksScheme($this->idHeader, $this->timestampHeader, $this->signatureHeader);
        }

        if ($this->schemeClass === Ed25519Scheme::class) {
            return new Ed25519Scheme($this->idHeader, $this->timestampHeader, $this->signatureHeader);
        }

        $scheme = app()->make($this->schemeClass);

        if (! $scheme instanceof SignatureScheme) {
            throw new InvalidArgumentException("The scheme for webhook client config [{$this->name}] did not resolve to a SignatureScheme.");
        }

        // Any other scheme is resolved from the container with no arguments, so it carries
        // its own default header names. Hand it the ones the host EXPLICITLY configured (a
        // null keeps the scheme's own default), so a provider that uses a different header —
        // PlainHmacScheme for SendCloud's 'Sendcloud-Signature', say — works from config
        // alone instead of forcing the host to write a scheme class just to bind the header.
        return $scheme instanceof AcceptsSignatureHeaders
            ? $scheme->withSignatureHeaders(
                $this->explicitHeaders['id'] ?? null,
                $this->explicitHeaders['timestamp'] ?? null,
                $this->explicitHeaders['signature'] ?? null,
            )
            : $scheme;
    }

    /**
     * The custom inbound verifier for this source, or null to fall back to the signature
     * scheme. When set it takes precedence over {@see self::scheme()} and authenticates
     * the request itself (an API callback, a cert chain) — so 'secret' is optional. The
     * pipeline calls this first; only its absence reaches the scheme.
     */
    public function verifier(): ?InboundVerifier
    {
        if ($this->verifierClass === null) {
            return null;
        }

        $verifier = app()->make($this->verifierClass);

        return $verifier instanceof InboundVerifier
            ? $verifier
            : throw new InvalidArgumentException("The verifier for webhook client config [{$this->name}] did not resolve to an InboundVerifier.");
    }

    public function tolerance(): int
    {
        return $this->tolerance;
    }

    public function invalidStatus(): int
    {
        return $this->invalidStatus;
    }

    public function profile(): WebhookProfile
    {
        $profile = app()->make($this->profileClass);

        return $profile instanceof WebhookProfile
            ? $profile
            : throw new InvalidArgumentException("The profile for webhook client config [{$this->name}] did not resolve to a WebhookProfile.");
    }

    public function response(): RespondsToWebhook
    {
        $response = app()->make($this->responseClass);

        return $response instanceof RespondsToWebhook
            ? $response
            : throw new InvalidArgumentException("The response for webhook client config [{$this->name}] did not resolve to a RespondsToWebhook.");
    }

    /**
     * @return class-string<WebhookCall>
     */
    public function model(): string
    {
        return $this->modelClass;
    }

    /**
     * The delivery's idempotency key — what the partial-unique store and the fast-path
     * cache dedupe on — or null when this producer offers none (the call is then always
     * stored). The 'dedupe_id' config selects where it comes from:
     *
     *   - unset          the configured id header (the Standard-Webhooks default)
     *   - 'header:Name'  an arbitrary header
     *   - 'body:path'    a dotted path into the JSON body (Stripe's evt_… lives there)
     *   - a class-string a {@see DedupeKeyResolver} the container resolves
     *
     * The body forms exist because real providers (Stripe, Mollie, SendCloud) carry no
     * delivery-id header, so a header-only key stays null — and a null never collides with
     * the partial-unique index, so dedupe silently does nothing for exactly those producers.
     */
    public function webhookId(SignatureHeaders $headers, string $rawBody = ''): ?string
    {
        $spec = $this->dedupeId;

        if ($spec === null) {
            return $this->nonEmpty($headers->get($this->idHeader));
        }

        if (str_starts_with($spec, 'header:')) {
            return $this->nonEmpty($headers->get(substr($spec, 7)));
        }

        if (str_starts_with($spec, 'body:')) {
            $value = data_get($this->decodeBody($rawBody), substr($spec, 5));

            return match (true) {
                is_string($value) => $this->nonEmpty($value),
                is_int($value) => (string) $value,
                default => null,
            };
        }

        $resolver = app()->make($spec);

        return $resolver instanceof DedupeKeyResolver
            ? $this->nonEmpty($resolver->resolve($this->decodeBody($rawBody), $rawBody, $headers))
            : throw new InvalidArgumentException("The 'dedupe_id' resolver for webhook client config [{$this->name}] did not resolve to a DedupeKeyResolver.");
    }

    /**
     * The handler job for an event type: the single configured job, the map entry
     * for this type, its '*' fallback, or the base job when nothing is configured.
     *
     * @return class-string<ProcessWebhookJob>|null
     */
    public function processJobFor(?string $type): ?string
    {
        if ($this->process === null) {
            return ProcessWebhookJob::class;
        }

        if (is_string($this->process)) {
            return $this->process;
        }

        return $this->process[$type ?? '*'] ?? $this->process['*'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function redact(): array
    {
        return $this->redact;
    }

    /**
     * @return '*'|list<string>
     */
    public function storeHeaders(): string|array
    {
        return $this->storeHeaders;
    }

    /**
     * The idempotency driver: 'redis+db' runs the cache fast path in front of the
     * partial-unique store, 'db' skips the fast path and relies on the store alone.
     */
    public function dedupe(): string
    {
        return $this->dedupe;
    }

    public function usesFastPathDedupe(): bool
    {
        return str_contains($this->dedupe, 'redis');
    }

    /**
     * Whether over-threshold bodies are written to a Storage disk instead of the
     * payload column (off by default).
     */
    public function largePayloadEnabled(): bool
    {
        return $this->largePayload['enabled'];
    }

    /**
     * The byte length above which a body is offloaded to disk.
     */
    public function largePayloadThreshold(): int
    {
        return $this->largePayload['threshold'];
    }

    /**
     * The Storage disk that holds offloaded bodies.
     */
    public function largePayloadDisk(): string
    {
        return $this->largePayload['disk'];
    }

    /**
     * The per-source inbound rate limit, or null when the source is unthrottled.
     *
     * @return array{max_attempts: int, decay_seconds: int}|null
     */
    public function rateLimit(): ?array
    {
        return $this->rateLimit;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private static function fromEntry(string $name, array $entry): self
    {
        $jwks = self::resolveJwks($name, $entry['jwks'] ?? null);
        $verifier = self::resolveVerifier($name, $entry['verifier'] ?? null);

        $secret = $entry['secret'] ?? null;

        // A static secret is required unless a JWKS url supplies the public keys, or a
        // custom verifier authenticates by other means (an API callback, a cert chain)
        // and needs no shared secret at all.
        if ($jwks === null && $verifier === null && (! is_string($secret) || $secret === '')) {
            throw new InvalidArgumentException("The webhook client config [{$name}] requires a non-empty 'secret', a 'jwks' url, or a 'verifier'.");
        }

        $previous = $entry['previous_secret'] ?? null;

        $headers = is_array($entry['signature_headers'] ?? null) ? $entry['signature_headers'] : [];

        return new self(
            name: $name,
            secret: is_string($secret) ? $secret : '',
            previousSecret: is_string($previous) && $previous !== '' ? $previous : null,
            schemeClass: self::resolveScheme($name, $entry['scheme'] ?? StandardWebhooksScheme::class),
            verifierClass: $verifier,
            idHeader: self::headerName($headers, 'id', StandardWebhooksScheme::HEADER_ID),
            timestampHeader: self::headerName($headers, 'timestamp', StandardWebhooksScheme::HEADER_TIMESTAMP),
            signatureHeader: self::headerName($headers, 'signature', StandardWebhooksScheme::HEADER_SIGNATURE),
            explicitHeaders: self::explicitHeaders($headers),
            tolerance: self::intOr($entry['tolerance_seconds'] ?? null, 300),
            invalidStatus: self::intOr($entry['invalid_status'] ?? null, 401),
            profileClass: self::classOr($name, 'profile', $entry['profile'] ?? null, WebhookProfile::class, ProcessEverythingWebhookProfile::class),
            responseClass: self::classOr($name, 'response', $entry['response'] ?? null, RespondsToWebhook::class, DefaultRespondsTo::class),
            modelClass: self::classOr($name, 'model', $entry['model'] ?? null, WebhookCall::class, WebhookCall::class),
            process: self::resolveProcess($name, $entry['process'] ?? null),
            redact: self::stringList($entry['redact'] ?? null, ['Authorization', 'Cookie']),
            storeHeaders: self::resolveStoreHeaders($entry['store_headers'] ?? null),
            dedupe: self::stringOr($entry['dedupe'] ?? null, 'redis+db'),
            dedupeId: self::resolveDedupeId($name, $entry['dedupe_id'] ?? null),
            jwksUrl: $jwks['url'] ?? null,
            jwksCacheTtl: $jwks['cacheTtl'] ?? 3600,
            jwksKid: $jwks['kid'] ?? null,
            largePayload: self::resolveLargePayload($entry['large_payload'] ?? null),
            rateLimit: self::resolveRateLimit($entry['rate_limit'] ?? null),
        );
    }

    /**
     * Normalise the optional 'large_payload' block into enabled + threshold + disk,
     * falling back to the documented defaults (off, 256 KiB, the 's3' disk).
     *
     * @return array{enabled: bool, threshold: int, disk: string}
     */
    private static function resolveLargePayload(mixed $largePayload): array
    {
        $block = is_array($largePayload) ? $largePayload : [];

        return [
            'enabled' => (bool) ($block['enabled'] ?? false),
            'threshold' => self::intOr($block['threshold'] ?? null, 262144),
            'disk' => self::stringOr($block['disk'] ?? null, 's3'),
        ];
    }

    /**
     * Normalise the optional 'rate_limit' block into a token-bucket size + decay, or
     * null when the source declares none (unthrottled).
     *
     * @return array{max_attempts: int, decay_seconds: int}|null
     */
    private static function resolveRateLimit(mixed $rateLimit): ?array
    {
        if (! is_array($rateLimit)) {
            return null;
        }

        return [
            'max_attempts' => self::intOr($rateLimit['max_attempts'] ?? null, 60),
            'decay_seconds' => self::intOr($rateLimit['decay_seconds'] ?? null, 60),
        ];
    }

    /**
     * Normalise the optional 'jwks' block into a url + cache TTL + optional kid, or
     * null when no JWKS is configured (the static-secret path).
     *
     * @return array{url: string, cacheTtl: int, kid: ?string}|null
     */
    private static function resolveJwks(string $name, mixed $jwks): ?array
    {
        if ($jwks === null) {
            return null;
        }

        if (! is_array($jwks)) {
            throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'jwks'; expected ['url' => ..., 'cache_ttl' => 3600].");
        }

        $url = $jwks['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException("The webhook client config [{$name}] 'jwks' requires a non-empty 'url'.");
        }

        $kid = $jwks['kid'] ?? null;

        return [
            'url' => $url,
            'cacheTtl' => self::intOr($jwks['cache_ttl'] ?? null, 3600),
            'kid' => is_string($kid) && $kid !== '' ? $kid : null,
        ];
    }

    /**
     * @return class-string<SignatureScheme>
     */
    private static function resolveScheme(string $name, mixed $scheme): string
    {
        if ($scheme === 'auto') {
            return StandardWebhooksScheme::class;
        }

        if (is_string($scheme) && is_a($scheme, SignatureScheme::class, true)) {
            return $scheme;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'scheme'; expected 'auto' or a SignatureScheme class-string.");
    }

    /**
     * @return class-string<InboundVerifier>|null
     */
    private static function resolveVerifier(string $name, mixed $verifier): ?string
    {
        if ($verifier === null) {
            return null;
        }

        if (is_string($verifier) && is_a($verifier, InboundVerifier::class, true)) {
            return $verifier;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'verifier'; expected an InboundVerifier class-string.");
    }

    /**
     * Validate the optional 'dedupe_id' strategy up front, so a typo fails at config load
     * rather than by silently keying dedupe on nothing. Returns the spec verbatim, or null
     * for the default (the id header).
     */
    private static function resolveDedupeId(string $name, mixed $spec): ?string
    {
        if ($spec === null) {
            return null;
        }

        if (is_string($spec) && (str_starts_with($spec, 'header:') || str_starts_with($spec, 'body:'))) {
            if ($spec === 'header:' || $spec === 'body:') {
                throw new InvalidArgumentException("The webhook client config [{$name}] 'dedupe_id' must name a header or body path after the prefix, e.g. 'header:X-Id' or 'body:data.id'.");
            }

            return $spec;
        }

        if (is_string($spec) && is_a($spec, DedupeKeyResolver::class, true)) {
            return $spec;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'dedupe_id'; expected 'header:Name', 'body:dotted.path', or a DedupeKeyResolver class-string.");
    }

    private function nonEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * The JSON body decoded to an array (empty for a non-array or invalid body), for the
     * 'body:' and resolver dedupe strategies.
     *
     * @return array<array-key, mixed>
     */
    private function decodeBody(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @template TObject of object
     *
     * @param  class-string<TObject>  $interface
     * @param  class-string<TObject>  $default
     * @return class-string<TObject>
     */
    private static function classOr(string $name, string $key, mixed $value, string $interface, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value) && is_a($value, $interface, true)) {
            return $value;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid '{$key}'.");
    }

    /**
     * @return class-string<ProcessWebhookJob>|array<string, class-string<ProcessWebhookJob>>|null
     */
    private static function resolveProcess(string $name, mixed $process): string|array|null
    {
        if ($process === null) {
            return null;
        }

        if (is_string($process)) {
            if (is_a($process, ProcessWebhookJob::class, true)) {
                return $process;
            }

            throw new InvalidArgumentException("The webhook client config [{$name}] 'process' must be a ProcessWebhookJob class-string.");
        }

        if (is_array($process)) {
            $map = [];

            foreach ($process as $type => $handler) {
                if (! is_string($type) || ! is_string($handler) || ! is_a($handler, ProcessWebhookJob::class, true)) {
                    throw new InvalidArgumentException("The webhook client config [{$name}] 'process' map must be ['event.type' => ProcessWebhookJob class-string].");
                }

                $map[$type] = $handler;
            }

            return $map;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'process'.");
    }

    /**
     * @param  array<array-key, mixed>  $headers
     */
    private static function headerName(array $headers, string $key, string $default): string
    {
        return self::stringOr($headers[$key] ?? null, $default);
    }

    /**
     * The header names the host EXPLICITLY set under 'signature_headers' — only these are
     * pushed onto a scheme with its own default, so an absent key never overwrites it.
     *
     * @param  array<array-key, mixed>  $headers
     * @return array{id?: string, timestamp?: string, signature?: string}
     */
    private static function explicitHeaders(array $headers): array
    {
        $explicit = [];

        foreach (['id', 'timestamp', 'signature'] as $key) {
            $value = $headers[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $explicit[$key] = $value;
            }
        }

        return $explicit;
    }

    private static function stringOr(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringList(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return '*'|list<string>
     */
    private static function resolveStoreHeaders(mixed $value): string|array
    {
        if ($value === '*') {
            return '*';
        }

        if (is_array($value)) {
            return array_values(array_filter($value, is_string(...)));
        }

        return [];
    }
}
