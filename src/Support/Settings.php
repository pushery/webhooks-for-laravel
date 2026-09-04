<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Console\PreflightCommand;
use Pushery\Webhooks\Core\Signing\Ed25519Scheme;
use Pushery\Webhooks\Core\Signing\SignatureScheme;
use Pushery\Webhooks\Core\Signing\StandardWebhooksScheme;
use Pushery\Webhooks\Server\Backoff\BackoffStrategy;
use Pushery\Webhooks\Server\Backoff\ExponentialWithJitter;
use Pushery\Webhooks\Server\Exceptions\MissingSigningKey;
use Pushery\Webhooks\Server\Exceptions\UnknownSignatureScheme;

/**
 * Typed reader over the package configuration, so the rest of the code never juggles mixed
 * config values. Every accessor carries the same default as config/webhooks.php, so a nested key
 * can never be undefined here.
 *
 * That default is not about `mergeConfigFrom`, which this package does not use. The sentence here
 * used to say it was — "mergeConfigFrom only shallow-merges top-level keys" — and that stopped
 * being the mechanism when {@see MergesPackageConfig} replaced it with {@see ConfigMerge::tree()},
 * a recursive merge that leaves no nested key missing. A reader who checked the claim would find no
 * `mergeConfigFrom` call anywhere in the package and reasonably conclude the defaults below are
 * redundant.
 *
 * They are not, and the reason is a different one: a host running a stale config cache is served
 * the array it cached, not a freshly merged tree. Nothing re-merges for them until they clear it.
 * So the default in each accessor is what stands between such a host and a null — and where that
 * null would switch a safety brake off rather than merely blank a value,
 * `ConfigDefaultsAreInSyncTest` holds it against the shipped file so the pair cannot drift.
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
     * The advisory for a half-installed icon stack, or null when the pair is coherent.
     *
     * `blade-icons` renders and `blade-heroicons` is the set the shipped screens ask for. With
     * either one missing, WireKit draws its inert placeholder where an icon belongs: the screens
     * render, they are simply without iconography. Both directions therefore cost the same, and
     * the message says which half to install rather than how bad it is.
     *
     * This used to say the renderer-alone case answers 500, and it did. An alias like `inbox`
     * resolves cleanly through a static preset table that never checks whether the SVG behind it
     * exists, so with `blade-icons` present and `blade-heroicons` absent the lookup reached a set
     * nobody registered and threw, taking down every screen that draws an icon — which through
     * buttons and dropdowns is every screen. WireKit's graceful path covered the unknown alias and
     * not the resolved-alias-missing-set case.
     *
     * That gap is closed upstream as of WireKit 2.38, which this package's `conflict` now requires,
     * so the state is no longer broken but merely incomplete, and a message still claiming a 500
     * would be the kind of overstatement that teaches a reader to skip the next one.
     *
     * Both inputs are ARGUMENTS rather than reads, so every state is testable on a tree that has
     * neither package — which is this one, and every CI lane.
     */
    public function iconPairingAdvisory(bool $rendererInstalled, bool $heroiconSetInstalled): ?string
    {
        if ($rendererInstalled === $heroiconSetInstalled) {
            return null;
        }

        // Named rather than left to the reader: told only that "the pair is incomplete", someone
        // reaches for the package the message mentions first, and on a host that already uses
        // blade-icons for its own set that is the one they already have.
        $missing = $rendererInstalled ? 'blade-ui-kit/blade-heroicons' : 'blade-ui-kit/blade-icons';

        return sprintf(
            'The icon pair is half installed: %s is missing, so the shipped screens draw '
            .'WireKit\'s inert placeholder where an icon belongs. They render — they are simply '
            .'without iconography. Install it: composer require %s.',
            $missing,
            $missing,
        );
    }

    /**
     * The 4xx status codes that stay retryable while `no_retry_on_4xx` is on.
     *
     * Values are read leniently on purpose. The only realistic way a string reaches this list is
     * a host building it from an environment variable — `explode(',', env('...'))` yields
     * `['408', '425', '429']` — and dropping those left the classifier with an EMPTY list, which
     * it reads as the deliberate statement "no 4xx is retryable" rather than as a fallback. The
     * host who wrote the three most retryable codes into their config got the exact opposite of
     * what they asked for: a 429 became a final failure, the Retry-After path was never entered,
     * and every one of those failures counted against the circuit breaker.
     *
     * @return list<int>
     */
    public function retryable4xx(): array
    {
        [$codes, $faults] = $this->parseRetryable4xx();
        unset($faults);

        return $codes;
    }

    /**
     * What was unusable in that list, as advisory lines for the preflight.
     *
     * The lenient read above fixes the common case and hides the rest, which would trade one
     * silence for another: a value nothing can make sense of is still dropped, and a list of
     * nothing but those still falls back to the default. Both are the host's mistake to see.
     *
     * @return list<string>
     */
    public function retryable4xxFaults(): array
    {
        [$codes, $faults] = $this->parseRetryable4xx();
        unset($codes);

        return $faults;
    }

    /**
     * @return array{0: list<int>, 1: list<string>}
     */
    private function parseRetryable4xx(): array
    {
        $default = [408, 425, 429];
        $configured = Config::array('webhooks.server.retryable_4xx', $default);

        $codes = [];
        $unusable = [];
        $inert = [];

        foreach ($configured as $value) {
            if (is_int($value)) {
                $code = $value;
            } elseif (is_string($value) && trim($value) !== '' && trim($value) === (string) (int) trim($value)) {
                // Exact round-trip, not is_numeric(): that also accepts '4.5e2' and ' 429abc'
                // under a loose cast, and a status code read out of either is a guess.
                $code = (int) trim($value);
            } else {
                $unusable[] = get_debug_type($value).' '.json_encode($value);

                continue;
            }

            // Kept, not dropped: the classifier only consults this list for a 4xx, so an entry
            // outside the range is already inert and removing it would change nothing. What it
            // needs is to be SAID — a host who wrote 503 here is waiting for a retry that the
            // list was never going to produce.
            if ($code < 400 || $code > 499) {
                $inert[] = $code;
            }

            $codes[] = $code;
        }

        $faults = [];

        if ($unusable !== []) {
            $faults[] = sprintf(
                'webhooks.server.retryable_4xx: %d entr%s could not be read as a status code and '
                .'%s ignored (%s). Write them as integers or as plain digit strings.',
                count($unusable),
                count($unusable) === 1 ? 'y' : 'ies',
                count($unusable) === 1 ? 'was' : 'were',
                implode(', ', $unusable),
            );
        }

        if ($inert !== []) {
            $faults[] = sprintf(
                'webhooks.server.retryable_4xx: %s outside the 4xx range, so %s never consulted — '
                .'this list only decides which 4xx stays retryable while no_retry_on_4xx is on.',
                implode(', ', array_map(strval(...), $inert)),
                count($inert) === 1 ? 'it is' : 'they are',
            );
        }

        // An explicitly empty list is a real choice and stays empty. A list the host FILLED that
        // survives as nothing is not the same statement, and reading it as one turns a typo into
        // "no 4xx is ever retried" — silently, because the config file still shows three codes.
        if ($configured !== [] && $codes === []) {
            return [$default, [...$faults, sprintf(
                'webhooks.server.retryable_4xx was set but nothing in it could be read, so the '
                .'default [%s] is in force. An empty list would have meant "no 4xx is retryable", '
                .'which is not what a non-empty setting asks for.',
                implode(', ', array_map(strval(...), $default)),
            )]];
        }

        return [$codes, $faults];
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

        if (is_string($connection)) {
            return $connection;
        }

        return null;
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
     * How many manual test pings one endpoint may receive per minute, or null for no
     * brake at all.
     *
     * Null and a non-positive value mean the same thing on purpose: a "limit" of zero
     * would refuse every ping, which is a way to break the feature by typo rather than a
     * setting anyone wants. Switching the brake off is spelled null.
     *
     * The shipped default is repeated here rather than left to the merge, and only because
     * of what an ABSENT key costs: it reads as null, which switches the brake off entirely.
     * A host running on a config cache built before this version upgraded still has the old
     * trimmed layer until it rebuilds, and that window should not be an unbraked one.
     * ConfigDefaultsAreInSyncTest holds the two numbers together.
     */
    public function testPingPerMinute(): ?int
    {
        $max = Config::get('webhooks.platform.test_ping.max_per_minute', 5);

        if (is_int($max) && $max > 0) {
            return $max;
        }

        return null;
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
     * Whether the delivery body is copied into the search index.
     *
     * Defaults to FALSE, and the fallback repeats the shipped default on purpose: an
     * absent key reads as null, and null must not mean "index the payload". A host on a
     * stale config cache is the exact case this protects.
     */
    public function searchIndexPayload(): bool
    {
        return Config::boolean('webhooks.search.index_payload', false);
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
        // 262144, matching config/webhooks.php — not 0. A host on a stale config cache is served
        // the array it cached rather than a freshly merged tree, so a cached `server` block with
        // large_payload.enabled = true but no threshold would otherwise fall to 0 here and offload
        // EVERY payload to disk, not just the large ones. (The reason used to be given as
        // mergeConfigFrom's shallow merge; this package merges recursively and does not call it.)
        return Config::integer('webhooks.server.large_payload.threshold', 262144);
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
     * Read numerically rather than through `Config::float()`, and this is the one knob where that
     * getter refuses the literal an operator would actually write. It throws an
     * InvalidArgumentException on an int, so the most natural way to say "score on success rate
     * alone" is also the way that breaks:
     *
     * 'weights' => ['success' => 1, 'latency' => 0, 'consecutive' => 0]
     *
     * Every one of those three is an int. Nothing about the config file suggests otherwise: it
     * ships `0.7` and `0.15` because those happen to have decimals, not because a rule says a whole
     * number is forbidden. And the throw lands on the health-scoring path, so it is a scheduled
     * command and a status board failing over a legal-looking setting.
     *
     * The same doctrine {@see PreflightCommand} states for
     * `Config::string()`: where a host's plausible value would make a typed getter throw, read
     * it null-safe and coerce.
     *
     * @return array{success: float, latency: float, consecutive: float}
     */
    public function healthWeights(): array
    {
        return [
            'success' => $this->weight('webhooks.platform.health.weights.success', 0.7),
            'latency' => $this->weight('webhooks.platform.health.weights.latency', 0.15),
            'consecutive' => $this->weight('webhooks.platform.health.weights.consecutive', 0.15),
        ];
    }

    /**
     * One health weight, accepting anything numeric a host might reasonably write.
     *
     * An int, a float and a numeric string all mean the same weight to a reader, so they mean
     * the same weight here. Anything else — an array, a bare word, null — falls back to the
     * shipped default rather than throwing: a mistyped weight must not take down the health
     * board, and the default is the value the config file shows beside the key anyway.
     */
    private function weight(string $key, float $default): float
    {
        $value = Config::get($key, $default);

        // Two statements rather than a ternary, for the coverage reason its siblings in this file
        // state. `ConstantFallbackVisibility` does not flag this one — its fallback is a variable
        // rather than a literal — but the measurement behind the rule is the same either way, and
        // the arm that would go unexercised is the one that keeps a mistyped weight from taking
        // the health board down.
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
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

        if (is_string($proxy) && $proxy !== '') {
            return $proxy;
        }

        return null;
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
     * The event types the catalog declares, in the order the host wrote them.
     *
     * The keys are cast because a catalog type that looks numeric ("1001") comes back
     * from PHP as an int key, and every consumer here wants a string.
     *
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return array_map(strval(...), array_keys($this->catalog()));
    }

    /**
     * The event types a subscription may be registered for, or NULL when the catalog
     * declares none.
     *
     * Null is the load-bearing value, not an empty list: the catalog ships EMPTY, and an
     * application that keeps it that way must go on registering any type it likes rather
     * than having every registration refused the day it upgrades. A caller turns null into
     * "no constraint"; only a populated catalog becomes an allowlist.
     *
     * With prefix wildcards on, the covering wildcards join the set. A wildcard that covers
     * a declared type is a legitimate registration — and one that covers nothing is the same
     * typo the allowlist exists to catch, so deriving them from the catalog rather than
     * accepting any `*`-suffixed string keeps both halves true.
     *
     * @return list<string>|null
     */
    public function acceptedEventTypes(): ?array
    {
        $types = $this->eventTypes();

        if ($types === []) {
            return null;
        }

        if (! Config::boolean('webhooks.platform.wildcards', false)) {
            return $types;
        }

        $wildcards = [];

        foreach ($types as $type) {
            foreach ($this->wildcardsFor($type) as $wildcard) {
                $wildcards[] = $wildcard;
            }
        }

        return array_values(array_unique([...$types, ...$wildcards]));
    }

    /**
     * The prefix wildcards that cover an event type, one per dot boundary: `a.b.c` yields
     * `a.*` and `a.b.*`. A dot-less type has none, so it only ever matches exactly.
     *
     * @return list<string>
     */
    public function wildcardsFor(string $eventType): array
    {
        $segments = explode('.', $eventType);
        $wildcards = [];
        $counter = count($segments);

        for ($i = 1; $i < $counter; $i++) {
            $wildcards[] = implode('.', array_slice($segments, 0, $i)).'.*';
        }

        return $wildcards;
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

        if (is_array($schema)) {
            return $schema;
        }

        return null;
    }
}
