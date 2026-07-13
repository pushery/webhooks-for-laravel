<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Illuminate\Support\Facades\Config;
use Webhooks\Core\Signing\Ed25519Scheme;
use Webhooks\Core\Signing\SignatureScheme;
use Webhooks\Core\Signing\StandardWebhooksScheme;
use Webhooks\Server\Backoff\BackoffStrategy;
use Webhooks\Server\Backoff\ExponentialWithJitter;
use Webhooks\Server\Exceptions\MissingSigningKey;
use Webhooks\Server\Exceptions\UnknownSignatureScheme;

/**
 * Typed reader over the package configuration, so the rest of the code never
 * juggles mixed config values. Every accessor carries the same default as
 * config/webhooks.php, so a partially-published config (mergeConfigFrom only
 * shallow-merges top-level keys) can never leave a nested key undefined.
 *
 * @internal
 */
final class Settings
{
    public function tries(): int
    {
        return Config::integer('webhooks.server.tries', 3);
    }

    public function timeout(): int
    {
        return Config::integer('webhooks.server.timeout', 5);
    }

    /**
     * The connect-phase timeout of an outbound delivery, in seconds.
     */
    public function connectTimeout(): int
    {
        return Config::integer('webhooks.server.connect_timeout', 3);
    }

    /**
     * The HTTP verb every delivery is sent with (post unless a host changes it).
     */
    public function httpVerb(): string
    {
        return strtolower(Config::string('webhooks.server.http_verb', 'post'));
    }

    /**
     * The configured retry schedule: exponential with full jitter, from the base
     * delay up to the cap. A call may swap in its own strategy per delivery.
     */
    public function backoffStrategy(): BackoffStrategy
    {
        return new ExponentialWithJitter(
            baseSeconds: Config::integer('webhooks.server.backoff.base', 10),
            capSeconds: Config::integer('webhooks.server.backoff.cap', 900),
            retryAfterCapSeconds: $this->retryAfterCap(),
        );
    }

    /**
     * The longest Retry-After wait the queue can hold a delivery job for. Separate from
     * the jitter cap on purpose: that one exists to stay under a queue's visibility
     * timeout, while an endpoint's rate-limit window is routinely much longer.
     */
    public function retryAfterCap(): int
    {
        return Config::integer('webhooks.server.backoff.retry_after_cap', 900);
    }

    /**
     * How many times a delivery may wait out a longer-than-cap Retry-After window
     * without charging its retry budget, before it is finally given up on.
     */
    public function retryAfterMaxDeferrals(): int
    {
        return Config::integer('webhooks.server.backoff.retry_after_max_deferrals', 6);
    }

    /**
     * How many bytes of an endpoint's response are captured for the delivery log. The
     * rest is read off the wire and discarded, so a hostile or broken endpoint cannot
     * make a worker buffer an unbounded body.
     */
    public function responseCaptureBytes(): int
    {
        return Config::integer('webhooks.server.response_capture_bytes', 65536);
    }

    /**
     * The dialect every outbound delivery is signed with unless a call overrides it
     * with ->signUsing(). Standard Webhooks by default; asymmetric Ed25519 (`v1a`)
     * when the Server's Ed25519 signing is switched on, since that key material —
     * not a per-endpoint shared secret — is what then signs the body.
     *
     * @return class-string<SignatureScheme>
     *
     * @throws UnknownSignatureScheme when the configured class is not a scheme.
     */
    public function signingScheme(): string
    {
        if ($this->ed25519Enabled()) {
            return Ed25519Scheme::class;
        }

        $scheme = Config::string('webhooks.core.signing.scheme', StandardWebhooksScheme::class);

        if (! is_a($scheme, SignatureScheme::class, true)) {
            throw UnknownSignatureScheme::for($scheme);
        }

        return $scheme;
    }

    /**
     * Whether the Server signs its deliveries asymmetrically with its own Ed25519
     * keypair. Off by default; while off, deliveries are signed with the symmetric
     * per-endpoint secret under the configured scheme.
     */
    public function ed25519Enabled(): bool
    {
        return Config::boolean('webhooks.server.signing.ed25519.enabled', false);
    }

    /**
     * The base64 Ed25519 secret key the Server signs with, or null while asymmetric
     * signing is off. Enabled without a key is a misconfiguration that would silently
     * fall back to an unsigned or wrongly-signed delivery, so it fails loudly here.
     *
     * @throws MissingSigningKey when asymmetric signing is on but no key is set.
     */
    public function ed25519SigningKey(): ?string
    {
        if (! $this->ed25519Enabled()) {
            return null;
        }

        $key = Config::get('webhooks.server.signing.ed25519.secret_key');

        if (! is_string($key) || $key === '') {
            throw MissingSigningKey::ed25519();
        }

        return $key;
    }

    public function verifySsl(): bool
    {
        return Config::boolean('webhooks.server.verify_ssl', true);
    }

    public function queue(): string
    {
        return Config::string('webhooks.server.queue', 'default');
    }

    public function connection(): ?string
    {
        $connection = Config::get('webhooks.server.connection');

        return is_string($connection) ? $connection : null;
    }

    public function rateLimitEnabled(): bool
    {
        return Config::boolean('webhooks.platform.rate_limit.enabled', true);
    }

    public function rateLimitPerMinute(): int
    {
        return Config::integer('webhooks.platform.rate_limit.max_per_minute', 60);
    }

    /**
     * How long an endpoint's rotated-away secret keeps signing and verifying. Zero
     * revokes it the instant it is rotated away.
     */
    public function secretRotationWindowHours(): int
    {
        return Config::integer('webhooks.platform.secret_rotation_window_hours', 24);
    }

    public function circuitBreakerEnabled(): bool
    {
        return Config::boolean('webhooks.platform.circuit_breaker.enabled', true);
    }

    public function circuitBreakerThreshold(): int
    {
        return Config::integer('webhooks.platform.circuit_breaker.threshold', 10);
    }

    public function horizonTags(): bool
    {
        return Config::boolean('webhooks.server.horizon_tags', true);
    }

    public function respectRetryAfter(): bool
    {
        return Config::boolean('webhooks.server.backoff.respect_retry_after', true);
    }

    /**
     * Whether the delivered JSON body is canonicalized (sorted keys, no
     * insignificant whitespace) before signing. Off by default.
     */
    public function canonicalizeJson(): bool
    {
        return Config::boolean('webhooks.server.signing.canonicalize', false);
    }

    /**
     * Whether the delivery and inbound-call logs are indexed for search. Off by
     * default, so a model reports shouldBeSearchable() false until a host opts in.
     */
    public function searchEnabled(): bool
    {
        return Config::boolean('webhooks.search.enabled', false);
    }

    /**
     * The maximum number of characters of an inline payload copied into the search
     * index. An offloaded payload is never indexed verbatim regardless of this.
     */
    public function searchPayloadExcerptChars(): int
    {
        return Config::integer('webhooks.search.payload_excerpt_chars', 500);
    }

    /**
     * Whether an over-threshold delivery-log payload is written to a Storage disk
     * instead of the payload column (off by default).
     */
    public function largePayloadEnabled(): bool
    {
        return Config::boolean('webhooks.server.large_payload.enabled', false);
    }

    /**
     * The byte length above which a delivery-log payload is offloaded to disk.
     */
    public function largePayloadThreshold(): int
    {
        return Config::integer('webhooks.server.large_payload.threshold', 0);
    }

    /**
     * The Storage disk that holds offloaded delivery-log payloads.
     */
    public function largePayloadDisk(): string
    {
        return Config::string('webhooks.server.large_payload.disk', 's3');
    }

    public function retentionMonths(): int
    {
        return Config::integer('webhooks.platform.retention_months', 3);
    }

    public function partitionMonthsAhead(): int
    {
        return Config::integer('webhooks.platform.partition_months_ahead', 3);
    }

    public function validatePayloads(): bool
    {
        return Config::boolean('webhooks.platform.validate_payloads', false);
    }

    /**
     * Whether a finished delivery auto-refreshes its endpoint's cached health score.
     * Off by default: the score query and the refresh command always work, but the
     * cached columns only move on a delivery when a host opts in.
     */
    public function healthEnabled(): bool
    {
        return Config::boolean('webhooks.platform.health.enabled', false);
    }

    /**
     * How many hours of recent delivery history the health score is computed over.
     */
    public function healthWindowHours(): int
    {
        return Config::integer('webhooks.platform.health.window_hours', 24);
    }

    /**
     * The minimum age (seconds) of a cached health score below which a delivery-driven
     * refresh is skipped, so a busy endpoint does not recompute its full percentile
     * window on every finished delivery. Zero recomputes on every finished delivery.
     */
    public function healthRefreshMinIntervalSeconds(): int
    {
        return Config::integer('webhooks.platform.health.refresh_min_interval_seconds', 60);
    }

    /**
     * The p95 latency (ms) at which the latency signal reaches its full penalty.
     */
    public function healthLatencyBudgetMs(): int
    {
        return Config::integer('webhooks.platform.health.latency_budget_ms', 2000);
    }

    /**
     * The consecutive-failure streak at which the failure-streak signal reaches its
     * full penalty.
     */
    public function healthConsecutivePenaltyAt(): int
    {
        return Config::integer('webhooks.platform.health.consecutive_penalty_at', 5);
    }

    /**
     * The relative weights of the three health signals (success rate, latency,
     * failure streak) in the blended score.
     *
     * @return array{success: float, latency: float, consecutive: float}
     */
    public function healthWeights(): array
    {
        return [
            'success' => Config::float('webhooks.platform.health.weights.success', 0.7),
            'latency' => Config::float('webhooks.platform.health.weights.latency', 0.15),
            'consecutive' => Config::float('webhooks.platform.health.weights.consecutive', 0.15),
        ];
    }

    /**
     * Whether per-endpoint payload transformation and versioning is active. Off by
     * default, so every endpoint receives the raw event payload unchanged.
     */
    public function payloadVersioningEnabled(): bool
    {
        return Config::boolean('webhooks.platform.payload_versioning.enabled', false);
    }

    /**
     * Whether the egress layer is switched on. It is the master gate: a proxy is only
     * routed through while this is true, so an operator can stand the proxy down
     * without deleting its URL from the environment.
     */
    public function egressEnabled(): bool
    {
        return Config::boolean('webhooks.core.egress.enabled', false);
    }

    /**
     * The egress proxy every outbound delivery routes through, or null for a direct
     * connection. Fail-closed: a configured proxy is used only while the egress layer
     * is enabled, because routing through a proxy WEAKENS the guard — the SSRF pin
     * (CURLOPT_RESOLVE) binds a DIRECT connection to the vetted IP, but a proxy
     * resolves the destination host itself, so the pin is NOT enforced through it and
     * the operator's proxy must enforce egress control.
     */
    public function egressProxy(): ?string
    {
        if (! $this->egressEnabled()) {
            return null;
        }

        $proxy = Config::get('webhooks.core.egress.proxy');

        return is_string($proxy) && $proxy !== '' ? $proxy : null;
    }

    /**
     * The full event catalog: a map of event type to its metadata (description,
     * example, schema). Keyed by the literal event type, which contains dots.
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        /** @var array<string, mixed> $catalog */
        $catalog = Config::array('webhooks.platform.catalog', []);

        return $catalog;
    }

    /**
     * The JSON Schema declared for an event type in the catalog, or null when the
     * type declares none. The catalog is indexed by the literal event type (which
     * contains dots, e.g. "invoice.paid"), not via dot-notation config access.
     *
     * @return array<array-key, mixed>|null
     */
    public function schemaFor(string $eventType): ?array
    {
        $entry = Config::array('webhooks.platform.catalog', [])[$eventType] ?? null;
        $schema = is_array($entry) ? ($entry['schema'] ?? null) : null;

        return is_array($schema) ? $schema : null;
    }
}
