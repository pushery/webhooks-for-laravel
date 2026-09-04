<?php

declare(strict_types=1);

namespace Pushery\Webhooks;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Pushery\Webhooks\Core\Payload\PayloadSanitizer;
use Pushery\Webhooks\Core\Payload\PayloadStore;
use Pushery\Webhooks\Core\Ssrf\SsrfGuard;
use Pushery\Webhooks\Database\OwnerKeyType;
use Pushery\Webhooks\Enums\DeliveryStatus;
use Pushery\Webhooks\Events\WebhookDeliveryRateLimited;
use Pushery\Webhooks\Events\WebhookEndpointRegistered;
use Pushery\Webhooks\Events\WebhookSecretRotated;
use Pushery\Webhooks\Exceptions\InvalidPayloadException;
use Pushery\Webhooks\Exceptions\SubscriptionNotListening;
use Pushery\Webhooks\Exceptions\TestPingThrottled;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Transform\PayloadTransformer;
use Pushery\Webhooks\Platform\Transform\PayloadVersionRegistry;
use Pushery\Webhooks\Search\SearchIndexer;
use Pushery\Webhooks\Server\Exceptions\DeliveryRefused;
use Pushery\Webhooks\Server\PendingWebhook;
use Pushery\Webhooks\Support\PayloadValidator;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Support\TenantIdentity;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;

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

        // Fired here rather than in the portal's form, so a host that registers endpoints
        // through its own screen or its own service layer gets the same record.
        Event::dispatch(new WebhookEndpointRegistered($subscription, $this->actor()));

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
     * circuit breaker auto-disabled (`Pushery\Webhooks\Events\WebhookEndpointAutoDisabled`), and
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
        return $this->writeLifecycle($subscription, [
            'is_active' => true,
            'disabled_at' => null,
            'consecutive_failures' => 0,
        ]);
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
        return $this->writeLifecycle($subscription, [
            'is_active' => false,
            'disabled_at' => now(),
        ]);
    }

    /**
     * Write the lifecycle columns of one subscription, through the query builder.
     *
     * Not `$subscription->save()`, and the reason is the circuit breaker. The breaker switches an
     * endpoint off with a conditional UPDATE through the query builder — it has to, because gating
     * on `is_active = true` is what makes only one of several concurrent workers fire the
     * auto-disabled event. A query-builder write does not touch an instance already in memory, so
     * any model loaded before the trip goes on reading `is_active = true, disabled_at = null` while
     * the row says the opposite.
     *
     * Assigning those same values to such a model leaves Eloquent with nothing dirty, and
     * `save()` then issues no UPDATE at all — while still returning normally, so the caller
     * sees a subscription handed back and reads it as a confirmation. The endpoint stays as
     * it was. Both directions fail this way, and the quieter one is `disable()`: a failed
     * enable is noticed the moment somebody waits for a webhook, a failed disable is noticed
     * only by whoever keeps receiving the traffic that was meant to stop.
     *
     * Writing through the builder makes the update unconditional on what the instance
     * happens to hold, which is the same reason the breaker writes that way. The instance is
     * then synced to what was just written — by assignment rather than by `refresh()`, which
     * would cost a second query and would throw on a row that has since been deleted, where
     * both this method and its predecessor are a silent no-op.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeLifecycle(WebhookSubscription $subscription, array $attributes): WebhookSubscription
    {
        // The timestamp is passed rather than left to the builder, which would add its own:
        // the instance has to end up carrying the SAME value the row got, and the only way to
        // be sure of that is to choose it here. Without it the object handed back reports a
        // change time from before the change — on the one call whose whole shape says "here
        // is your subscription, it is done".
        $attributes['updated_at'] = $subscription->freshTimestamp();

        // Every timestamp goes through the model's own conversion, and leaving it out is the reason
        // this method needed a second read. A query-builder update binds a DateTimeInterface
        // through the query grammar and never reaches {@see \Pushery\Webhooks\Database\Concerns\HasZonedTimestamps::fromDateTime()},
        // the one place this package normalizes a timestamp per engine. `save()` went through it;
        // this does not, so writing `now()` straight through stores an instant an offset away from
        // the one meant. Nothing complains: both values are real timestamps.
        //
        // Measured at an hour off on this machine, and disabled_at is what the active() scope
        // filters on — an endpoint switched off an hour in the future is one the scope still
        // treats as live.
        // Into a second array rather than over the first, and that is not tidiness. The stored form
        // goes to the builder; the instance below gets the original values, because forceFill()
        // routes everything through setAttribute(), which runs fromDateTime() again on any date
        // attribute. On PostgreSQL that second pass is idempotent — the string carries its offset
        // and parses back — but on MySQL the stored form is UTC-naive, so the re-parse resolves it
        // against the PHP default zone and shifts it a second time. The row would be right and the
        // returned instance an app-timezone offset away from it, with syncOriginalAttributes
        // pinning the wrong value as original so nothing reports itself dirty. Converting once,
        // exactly as save() did, is the point.
        $stored = $attributes;

        foreach ($stored as $column => $value) {
            if ($value instanceof DateTimeInterface) {
                $stored[$column] = $subscription->fromDateTime($value);
            }
        }

        // Through the BASE builder, and the reason is that by this point there is nothing left
        // for the Eloquent layer to contribute: the values are already in their stored form,
        // `updated_at` is supplied above rather than added, the model declares no global scope,
        // and nothing listens for its model events. It is also the same kind of statement the
        // circuit breaker writes — which is the symmetry this whole method exists to restore.
        $subscription->newQuery()->toBase()
            ->where($subscription->getKeyName(), $subscription->getKey())
            ->update($stored);

        // forceFill because the lifecycle columns are guarded — engine-owned, never
        // mass-assignable — and this IS the engine.
        //
        // syncOriginalAttributes rather than syncOriginal, and the difference is silent data loss
        // in the caller. syncOriginal() marks every attribute clean, including a change the caller
        // made and has not saved yet, which this method did not write. Their own save() afterwards
        // then finds nothing dirty and writes nothing, and the edit is gone with no error anywhere.
        // Only the columns actually written may be marked clean.
        $subscription->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));

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
     * configured owner_key_type. The package denormalizes owner_id across the subscriptions
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
            // EQUIVALENT, and reported every run: the value goes to a `%s` in sprintf, which
            // renders an int identically. It is written out so all three arms of this match
            // answer the same TYPE — the variable is a message fragment, not a key.
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
     * Deliver an application event to exactly ONE subscription.
     *
     * For a host that routes rule → endpoint rather than event → every endpoint of that
     * type. `dispatch()` cannot express it: its third argument narrows to a TENANT, and
     * two endpoints of the same customer share one. Neither can the two methods that do
     * take a single subscription — `ping()` sends a fixed `webhooks.ping` body, and
     * `redeliver()` needs a delivery that already exists.
     *
     * It is the same delivery, not a second kind of one. The payload goes through the
     * catalog schema, the NUL scrub and the same offload; the send re-validates SSRF,
     * signs, honours the circuit breaker, and is SHAPED by the rate limit rather than
     * discarded by it. A targeted send with its own semantics would be a second surface
     * to keep in step, and the gaps would open in the copy nobody reads.
     *
     * Eligibility is decided by re-running the fan-out's OWN scopes against this one
     * subscription rather than by re-reading `is_active` here. That is the whole point:
     * `active()` and `listeningFor()` already encode auto-disabling and the wildcard
     * setting, and a second opinion on "may this endpoint have this event" is a second
     * opinion that drifts.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidPayloadException when the payload fails its catalog schema
     * @throws SubscriptionNotListening when the endpoint is inactive, or not subscribed
     */
    public function dispatchTo(WebhookSubscription $subscription, string $eventType, array $payload): WebhookDelivery
    {
        $this->payloadValidator->validate($eventType, $payload);

        $eligible = WebhookSubscription::query()
            ->whereKey($subscription->getKey())
            ->active()
            ->listeningFor($eventType)
            ->exists();

        if (! $eligible) {
            // The reason is re-read rather than taken off the model. The eligibility above is
            // decided by the fan-out's own scopes against the database, for the reason this
            // method's docblock gives: a second opinion drifts. Reading `is_active` off the
            // instance here to explain the refusal was that second opinion, and it drifts in
            // exactly the case that matters — right after the circuit breaker has switched the
            // endpoint off through the query builder, the row is disabled and a model loaded before
            // the trip is not. The operator was then told the endpoint "does not subscribe to that
            // event type" about an endpoint subscribed to it, and sent to change event types that
            // were already right.
            $live = WebhookSubscription::query()
                ->whereKey($subscription->getKey())
                ->active()
                ->exists();

            throw new SubscriptionNotListening(
                $subscription,
                $eventType,
                $live
                    ? 'it does not subscribe to that event type'
                    : 'it is not active',
            );
        }

        $payload = PayloadSanitizer::scrub($payload);

        return $this->deliver(
            $subscription,
            $eventType,
            (string) Str::uuid7(),
            $payload,
            null,
            $this->rateLimitDelayFor($subscription),
        );
    }

    /**
     * Send a one-off test event to a single subscription.
     *
     * The delivery rate limit is bypassed on purpose — an operator has to be able to prove
     * an endpoint answers while it is over its allowance. What that exemption cost was a
     * bound of any kind on the one send a human repeats at will, aimed at a destination the
     * requester chose: the SSRF guard decides where a ping may land, never how often. So
     * this has a brake of its own, per endpoint.
     *
     * @throws TestPingThrottled when the endpoint is over its test-ping allowance
     */
    public function ping(WebhookSubscription $subscription): WebhookDelivery
    {
        $this->guardTestPingAllowance($subscription);

        return $this->deliver(
            $subscription,
            'webhooks.ping',
            (string) Str::uuid7(),
            ['message' => 'This is a test event from Webhooks for Laravel.'],
        );
    }

    /**
     * Refuse a test ping once the endpoint has had its allowance for the current minute.
     *
     * Refused, not deferred: a real event that arrives two minutes late still means what
     * it meant, while a test ping that does has already failed at the only thing it was
     * for. The bucket is only hit once the ping is allowed through, so a refusal does not
     * push the next opening further out — an over-eager caller stops making it worse for
     * itself the moment it stops.
     *
     * @throws TestPingThrottled
     */
    private function guardTestPingAllowance(WebhookSubscription $subscription): void
    {
        $max = $this->config->testPingPerMinute();

        if ($max === null) {
            return;
        }

        $key = "webhooks:test-ping:{$subscription->id}";

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new TestPingThrottled($subscription, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::RATE_LIMIT_WINDOW);
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

        Event::dispatch(new WebhookSecretRotated($subscription, $this->actor()));

        return $subscription->secret;
    }

    /**
     * Who is acting, for the events that record a person rather than a delivery.
     *
     * Null outside a session — a console command, a seeder, a queued job — which is the
     * whole reason the events carry a nullable actor.
     *
     * This used to check that the container had `auth` bound first, on the theory that a
     * send-only or console-driven host might register no guard. Coverage said otherwise by
     * refusing to reach the branch, and it was right: this package requires
     * laravel/framework, so the auth manager is bound in every host that can install it.
     * The check could not fire, and a guard that cannot fire only makes the next reader
     * believe in a case that does not exist.
     */
    private function actor(): ?Authenticatable
    {
        return Auth::user();
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
                // Rendered for the webhook connection's dialect, because the lifecycle lookup
                // compares it against the created_at column and the two engines hold that column
                // in incompatible literal shapes — see Timestamp::forDialect() for why the wrong
                // one strands every delivery at pending instead of failing.
                'delivery_created_at' => Timestamp::forDialect(WebhookConnection::dialect(), $delivery->created_at),
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
