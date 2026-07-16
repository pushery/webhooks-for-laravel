<?php

declare(strict_types=1);

namespace Webhooks;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webhooks\Core\Payload\PayloadSanitizer;
use Webhooks\Core\Payload\PayloadStore;
use Webhooks\Core\Ssrf\SsrfGuard;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\OwnerKeyType;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Events\WebhookDeliveryRateLimited;
use Webhooks\Exceptions\InvalidPayloadException;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Transform\PayloadTransformer;
use Webhooks\Platform\Transform\PayloadVersionRegistry;
use Webhooks\Search\SearchIndexer;
use Webhooks\Server\Exceptions\DeliveryRefused;
use Webhooks\Server\PendingWebhook;
use Webhooks\Support\PayloadValidator;
use Webhooks\Support\Settings;
use Webhooks\Support\TenantIdentity;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

/**
 * The package's public entry point: register endpoints, fan an event out to every
 * matching subscription, and test or replay individual deliveries. Each delivery is
 * handed to the in-house delivery engine ({@see PendingWebhook}), which queues, signs
 * and retries the call and reports its fate through the delivery lifecycle events.
 */
final readonly class WebhookManager
{
    /** The rate limit's window, in seconds — the "per minute" of max_per_minute. */
    private const int RATE_LIMIT_WINDOW = 60;

    public function __construct(
        private SsrfGuard $guard,
        private Settings $config,
        private PayloadValidator $payloadValidator,
        private PayloadStore $payloadStore,
        private PayloadTransformer $transformer,
        private PayloadVersionRegistry $versionRegistry,
    ) {}

    /**
     * Register a new endpoint. The URL is SSRF-validated before it is stored. The owner
     * may be an Eloquent model (its morph class + key are stored) or an explicit
     * TenantIdentity — the self-service portal passes the resolved current tenant so the
     * stored owner matches exactly what the read scope filters by. A null owner registers
     * a global, owner-less subscription.
     *
     * @param  array<array-key, string>  $eventTypes
     */
    public function subscribe(Model|TenantIdentity|null $owner, string $url, array $eventTypes, ?string $name = null): WebhookSubscription
    {
        // Vet the URL against the shared SSRF policy; the pinned endpoint it returns
        // is not needed here — a blocked URL throws BlockedDestination.
        $this->guard->resolveAndPin($url);

        $subscription = new WebhookSubscription;
        $subscription->name = $name;
        $subscription->url = $url;
        $subscription->event_types = array_values($eventTypes);
        $subscription->is_active = true;
        $subscription->secret = $this->generateSecret();

        $this->assignOwner($subscription, $owner);

        $subscription->save();

        return $subscription;
    }

    /**
     * Permanently remove an endpoint. Its delivery-log rows are removed with it (the
     * subscription_id FK cascades), so this is the "forget this endpoint" operation —
     * to stop delivering while keeping the history, use {@see self::disable()}.
     */
    public function unsubscribe(WebhookSubscription $subscription): void
    {
        $subscription->delete();
    }

    /**
     * Bring an endpoint back into delivery — the recovery path for an endpoint the
     * circuit breaker auto-disabled (`Webhooks\Events\WebhookEndpointAutoDisabled`), and
     * the ONE place that knows what re-enabling means.
     *
     * Flipping is_active alone is not enough, and getting it wrong is silent: the
     * consecutive-failure streak that tripped the breaker is still standing, so the very
     * next final failure re-trips it at once and the endpoint disables again. Clearing
     * the streak here is what makes the endpoint genuinely live again — it gets the same
     * full budget of failures a fresh endpoint gets. disabled_at is cleared too, because
     * the active() scope filters on both columns.
     */
    public function enable(WebhookSubscription $subscription): WebhookSubscription
    {
        // The lifecycle columns are guarded (engine-owned, never mass-assignable), so
        // they are written by direct assignment.
        $subscription->is_active = true;
        $subscription->disabled_at = null;
        $subscription->consecutive_failures = 0;
        $subscription->save();

        return $subscription;
    }

    /**
     * Stop delivering to an endpoint, stamping when it went dark. The endpoint keeps its
     * secret, its subscriptions and its delivery history, so {@see self::enable()} brings
     * it back exactly as it was. A delivery already queued for it is refused at send time
     * by the delivery gate, so switching an endpoint off takes effect immediately rather
     * than after the queue drains.
     */
    public function disable(WebhookSubscription $subscription): WebhookSubscription
    {
        $subscription->is_active = false;
        $subscription->disabled_at = now();
        $subscription->save();

        return $subscription;
    }

    /**
     * Stamp the owning tenant onto a fresh subscription. An explicit model is associated
     * as the morphTo owner (explicit wins); a TenantIdentity sets the morph columns
     * directly; a null owner leaves the subscription global and owner-less.
     */
    private function assignOwner(WebhookSubscription $subscription, Model|TenantIdentity|null $owner): void
    {
        if ($owner instanceof Model) {
            $this->ensureOwnerKeyStorable($owner->getKey());
            $subscription->owner()->associate($owner);

            return;
        }

        if ($owner instanceof TenantIdentity) {
            $this->ensureOwnerKeyStorable($owner->id);
            $subscription->owner_type = $owner->type;
            $subscription->owner_id = $owner->id;
        }
    }

    /**
     * Fail fast, with a clear message, when an owner's primary key cannot be stored as the
     * configured owner_key_type. The package denormalises owner_id across the subscriptions
     * table, the delivery log and the dashboard rollup; the three must share one type, so an
     * owner whose key does not match the configured one (a UUID owner under the bigint default,
     * say) is rejected here rather than surfacing as an opaque insert error on the first
     * fan-out. A bigint key may arrive as a numeric string — the driver returns bigints as
     * strings — and matches all the same.
     */
    private function ensureOwnerKeyStorable(mixed $key): void
    {
        $type = OwnerKeyType::fromConfig();

        if ((is_int($key) || is_string($key)) && $type->accepts($key)) {
            return;
        }

        $shown = match (true) {
            is_string($key) => "'".$key."'",
            is_int($key) => (string) $key,
            default => gettype($key),
        };

        throw new InvalidArgumentException(sprintf(
            'A webhook subscription owner key (%s) cannot be stored as the configured '
            .'webhooks.platform.owner_key_type "%s". Set it to match the primary-key type of your '
            .'owner models (bigint, uuid or ulid) and migrate, or leave the subscription global by '
            .'passing a null owner.',
            $shown,
            $type->value,
        ));
    }

    /**
     * Fan an event out to every active subscription that listens for it, scoped to
     * the given tenant (plus any global, owner-less subscriptions). When payload
     * validation is enabled, the payload is checked against the event type's
     * catalog schema first, so a malformed event is rejected before any delivery.
     *
     * @param  array<array-key, mixed>  $payload
     * @return Collection<int, WebhookDelivery>
     *
     * @throws InvalidPayloadException
     */
    public function dispatch(string $eventType, array $payload, ?Model $tenant = null): Collection
    {
        $this->payloadValidator->validate($eventType, $payload);

        // Scrub NUL bytes ONCE, at the edge: the delivery log's payload column is jsonb,
        // which cannot hold one at all, and scrubbing here (rather than only on the way
        // into the column) keeps the logged copy and the signed, delivered bytes
        // identical — so a redelivery reproduces exactly what the endpoint first saw.
        $payload = PayloadSanitizer::scrub($payload);

        $eventId = (string) Str::uuid7();

        $subscriptions = WebhookSubscription::query()
            ->active()
            ->listeningFor($eventType)
            ->forTenant($tenant)
            ->get()
            ->values();

        // Encode (and, when offload is on, write) the shared event payload ONCE for the
        // whole fan-out — every matching endpoint logs the identical body, so a
        // content-addressed offload stores a single blob reused across all deliveries
        // instead of one round-trip + hash per subscriber. Skipped when nothing matches.
        $logged = $subscriptions->isEmpty() ? null : $this->loggedPayload($payload);

        return $subscriptions
            ->map(fn (WebhookSubscription $subscription): WebhookDelivery => $this->deliver(
                $subscription,
                $eventType,
                $eventId,
                $payload,
                $logged,
                // The rate limit SHAPES an endpoint's traffic; it does not throw the event
                // away. An over-limit delivery is logged and enqueued with a delay instead
                // of being dropped without a row, an event or a line in any log — the
                // silent gap an operator only learns about from the customer who never
                // received the webhook.
                $this->rateLimitDelayFor($subscription),
            ))
            ->values();
    }

    /**
     * Send a one-off test event to a single subscription (rate limit bypassed).
     */
    public function ping(WebhookSubscription $subscription): WebhookDelivery
    {
        return $this->deliver(
            $subscription,
            'webhooks.ping',
            (string) Str::uuid7(),
            ['message' => 'This is a test event from Webhooks for Laravel.'],
        );
    }

    /**
     * Rotate an endpoint's signing secret. The current secret is kept as the previous
     * one so signatures made with it keep verifying for the length of the configured
     * rotation window, a freshly generated secret becomes current, and the rotation
     * time is stamped. The new plaintext secret is returned so it can be revealed once.
     *
     * The window is what makes this a rotation rather than a formality: it CLOSES.
     * Once it has, the old secret is cleared from the row and can never sign or verify
     * again ({@see self::revokeExpiredSecret()}) — a previous secret that lingers for
     * ever revokes nothing, which is the one thing a compromised secret must be.
     */
    public function rotateSecret(WebhookSubscription $subscription): string
    {
        $subscription->previous_secret = $subscription->secret;
        $subscription->secret = $this->generateSecret();
        $subscription->secret_rotated_at = now();
        $subscription->save();

        return $subscription->secret;
    }

    /**
     * Clear an endpoint's rotated-away secret once its window has closed, so it stops
     * signing deliveries and stops verifying anything. Called before every delivery and
     * by the scheduled sweep, so a busy endpoint revokes on its next delivery and a
     * dormant one on the next sweep.
     *
     * @return bool whether a secret was revoked
     */
    public function revokeExpiredSecret(WebhookSubscription $subscription): bool
    {
        if ($subscription->previous_secret === null || $this->rotationWindowIsOpen($subscription)) {
            return false;
        }

        $subscription->previous_secret = null;
        $subscription->save();

        return true;
    }

    /**
     * Replay a delivery. A fresh log entry is created but the original event id is
     * kept, so a consumer that deduplicates on it treats this as the same event.
     *
     * @throws DeliveryRefused when the endpoint is no longer active — a replay must not
     *                         reach an endpoint its tenant switched off.
     */
    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        $subscription = $delivery->subscription;

        if (! $subscription->is_active) {
            throw DeliveryRefused::because('The endpoint is disabled; the delivery was not replayed.');
        }

        return $this->deliver(
            $subscription,
            $delivery->event_type,
            $delivery->event_id,
            $delivery->rehydratedPayload(),
        );
    }

    /**
     * A single delivery. In a fan-out the caller precomputes the logged payload once
     * and passes it in (every endpoint logs the identical body); a one-off delivery
     * (ping/redeliver) passes null and it is computed here.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array{payload: array<array-key, mixed>, disk: string|null, path: string|null, sha256: string|null}|null  $logged
     */
    private function deliver(WebhookSubscription $subscription, string $eventType, string $eventId, array $data, ?array $logged = null, int $delaySeconds = 0): WebhookDelivery
    {
        // The log stores the RAW event payload, not the transformed body: a redelivery
        // re-runs the transform over the raw payload and so reproduces the exact same
        // outbound bytes, without ever transforming an already-transformed body twice.
        $logged ??= $this->loggedPayload($data);

        // The tenant/identity and outcome columns are guarded, so the row is written
        // with forceFill rather than mass-assignment — the log is engine-owned, never
        // populated from host input.
        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'subscription_id' => $subscription->id,
            'owner_type' => $subscription->owner_type,
            'owner_id' => $subscription->owner_id,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'payload' => $logged['payload'],
            'payload_disk' => $logged['disk'],
            'payload_path' => $logged['path'],
            'body_sha256' => $logged['sha256'],
            'status' => DeliveryStatus::Pending,
            'attempt' => 0,
        ])->save();

        // Index the new row for Scout. The log is written through the base model, which never
        // fires Scout's per-subclass observer, so without this an external engine (Meilisearch,
        // …) would never see the delivery. A no-op unless search is on and the host pointed its
        // search source at a Searchable model.
        SearchIndexer::indexDelivery($delivery->id);

        // Reshape the event data for THIS endpoint before the body is built and signed,
        // so the transformed bytes are the signed-and-sent bytes. The outbound envelope
        // always carries the full data; only the logged copy is ever offloaded, so a
        // large payload leaves the delivery itself unchanged.
        $outbound = $this->transformFor($subscription, $data);

        $this->dispatchCall($subscription, $delivery, $eventType, $eventId, $outbound, $delaySeconds);

        if ($delaySeconds > 0) {
            Event::dispatch(new WebhookDeliveryRateLimited($delivery, $delaySeconds));
        }

        return $delivery;
    }

    /**
     * Reshape an event payload for a single endpoint. When versioning is off, or the
     * endpoint declares neither a stored transform nor a payload version, the payload
     * is returned unchanged — so an endpoint with nothing configured keeps receiving
     * exactly what it does today. Otherwise the endpoint's own transform rules (or the
     * default rules of its named version) are applied and its version id stamped.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function transformFor(WebhookSubscription $subscription, array $data): array
    {
        if (! $this->config->payloadVersioningEnabled()) {
            return $data;
        }

        $version = $subscription->payload_version;
        $rules = $subscription->transform ?? $this->versionRegistry->rulesFor($version);

        if ($rules === null && $version === null) {
            return $data;
        }

        return $this->transformer->transform($data, $rules, $version);
    }

    /**
     * The payload column plus optional offload pointer for a delivery-log row. When
     * offload is enabled and the encoded payload clears the byte threshold, it is
     * written to the Storage disk and the row keeps only a stub plus the pointer;
     * otherwise the payload is stored inline and the pointer stays null.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{payload: array<array-key, mixed>, disk: string|null, path: string|null, sha256: string|null}
     */
    private function loggedPayload(array $data): array
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! $this->config->largePayloadEnabled() || strlen($encoded) <= $this->config->largePayloadThreshold()) {
            return ['payload' => $data, 'disk' => null, 'path' => null, 'sha256' => null];
        }

        $disk = $this->config->largePayloadDisk();
        $pointer = $this->payloadStore->offload($encoded, $disk);

        return [
            'payload' => isset($data['type']) && is_string($data['type']) ? ['type' => $data['type']] : [],
            'disk' => $disk,
            'path' => $pointer['path'],
            'sha256' => $pointer['sha256'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function dispatchCall(WebhookSubscription $subscription, WebhookDelivery $delivery, string $eventType, string $eventId, array $data, int $delaySeconds = 0): void
    {
        $envelope = [
            'id' => $eventId,
            'type' => $eventType,
            'created_at' => now()->toISOString(),
            'data' => $data,
        ];

        // The message id is the event id, so the Standard Webhooks webhook-id header
        // matches the delivery-log event_id and a receiver can dedupe a redelivery.
        // The scheme sets the webhook-id/timestamp/signature headers at send time.
        //
        // The engine's configured defaults — signing dialect, verb, timeouts, tries,
        // TLS verification, canonicalization, retry schedule, Retry-After policy and
        // the egress proxy — are already seeded by PendingWebhook::create(), so fan-out
        // adds only what is specific to THIS event and endpoint.
        $call = PendingWebhook::create()
            ->url($subscription->url)
            ->payload($envelope)
            ->useMessageId($eventId)
            ->forEventType($eventType)
            ->onQueue($this->config->queue())
            ->onConnection($this->config->connection())
            ->meta([
                'delivery_id' => $delivery->id,
                // The log is range-partitioned by created_at and its primary key is
                // (id, created_at), so carrying the partition key with the id lets every
                // lifecycle listener prune straight to the one partition that holds the
                // row instead of probing the index of every partition that ever existed.
                // Rendered for the webhook connection's dialect: the lifecycle lookup compares
                // it against the created_at column, which is an offset-bearing timestamptz on
                // PostgreSQL but a UTC-naive DATETIME(6) on MySQL — a PG offset literal matches
                // ZERO naive rows under MySQL's strict mode, stranding every delivery at pending.
                'delivery_created_at' => WebhookConnection::dialect() === Dialect::MySql
                    ? Timestamp::mysql($delivery->created_at)
                    : Timestamp::sql($delivery->created_at),
                'subscription_id' => $subscription->id,
                'event_id' => $eventId,
            ])
            ->withTags($this->tags($subscription, $eventType));

        if ($delaySeconds > 0) {
            $call = $call->delayInSeconds($delaySeconds);
        }

        // Asymmetric signing uses the Server's own Ed25519 key (seeded onto the call),
        // so the endpoint's shared secret plays no part — the receiver holds only the
        // public key. Under a symmetric dialect the endpoint's secret signs, and — only
        // while the rotation window is still open — the previous one signs too, so a
        // consumer that still holds the old secret keeps verifying while it migrates.
        // Once that window closes the old secret is revoked here, on the spot: a
        // previous secret that keeps producing valid signatures for ever is a rotation
        // that revokes nothing.
        if (! $this->config->ed25519Enabled()) {
            $this->revokeExpiredSecret($subscription);

            $call = $subscription->previous_secret !== null
                ? $call->useSecrets($subscription->secret, $subscription->previous_secret)
                : $call->useSecret($subscription->secret);
        }

        $call->dispatch();
    }

    /**
     * Whether an endpoint's rotation window is still open — i.e. whether its previous
     * secret may still sign. A window of zero hours revokes immediately; a previous
     * secret with no rotation stamp (a row written by hand) is treated as expired.
     */
    private function rotationWindowIsOpen(WebhookSubscription $subscription): bool
    {
        $rotatedAt = $subscription->secret_rotated_at;

        if ($rotatedAt === null) {
            return false;
        }

        return $rotatedAt->addHours($this->config->secretRotationWindowHours())->isFuture();
    }

    /**
     * How long THIS delivery must wait before it may be sent, in seconds — zero while
     * the endpoint is inside its allowance.
     *
     * Over the allowance, the delivery is deferred rather than dropped: it waits out the
     * current window, and each further over-limit delivery in the same window is pushed
     * one window further, so a burst of a thousand events is spread across the following
     * minutes at exactly max_per_minute instead of being thrown away. Dropping them —
     * with no row, no event and no log line — leaves an operator with nothing to look at
     * but a customer reporting a webhook that never arrived.
     */
    private function rateLimitDelayFor(WebhookSubscription $subscription): int
    {
        if (! $this->config->rateLimitEnabled()) {
            return 0;
        }

        $key = "webhooks:dispatch:{$subscription->id}";
        $limit = max(1, $this->config->rateLimitPerMinute());

        // The bucket is hit for every delivery, over the limit or not, so the overflow
        // keeps counting and each excess delivery lands in a later window than the last.
        $hits = RateLimiter::hit($key, self::RATE_LIMIT_WINDOW);

        if ($hits <= $limit) {
            return 0;
        }

        $overflow = $hits - $limit;

        return RateLimiter::availableIn($key) + intdiv($overflow - 1, $limit) * self::RATE_LIMIT_WINDOW;
    }

    /**
     * @return list<string>
     */
    private function tags(WebhookSubscription $subscription, string $eventType): array
    {
        if (! $this->config->horizonTags()) {
            return [];
        }

        return ['webhooks', "subscription:{$subscription->id}", "event:{$eventType}"];
    }

    private function generateSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
