<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Pushery\Webhooks\Core\Http\ErrorMessageRedactor;
use Pushery\Webhooks\Database\PartitionManager;
use Pushery\Webhooks\Enums\DeliveryStatus;
use Pushery\Webhooks\Events\WebhookDeliveryFailed;
use Pushery\Webhooks\Events\WebhookDeliverySucceeded;
use Pushery\Webhooks\Events\WebhookEndpointAutoDisabled;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Search\SearchIndexer;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Events\WebhookAttemptFailed;
use Pushery\Webhooks\Server\Events\WebhookAttemptsExhausted;
use Pushery\Webhooks\Server\Events\WebhookAttemptSucceeded;
use Pushery\Webhooks\Server\Exceptions\DeliveryRefused;
use Pushery\Webhooks\Support\Settings;
use Throwable;

/**
 * Translates the delivery engine's lifecycle events into delivery-log updates and
 * drives the circuit breaker. Every handler locates its row by the delivery id
 * carried in the delivery meta and is idempotent: it never re-processes a delivery
 * that is already in a terminal state (Succeeded or Exhausted), so a duplicated or
 * out-of-order event — a known at-least-once queue edge case — can neither
 * downgrade a success nor double-count a failure.
 *
 * @internal
 */
final readonly class WebhookServerEventSubscriber
{
    private const string DEFAULT_ERROR = 'Webhook delivery failed.';

    public function __construct(
        private Settings $config,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WebhookAttemptSucceeded::class, [self::class, 'onSucceeded']);
        $events->listen(WebhookAttemptFailed::class, [self::class, 'onFailed']);
        $events->listen(WebhookAttemptsExhausted::class, [self::class, 'onFinalFailed']);
    }

    public function onSucceeded(WebhookAttemptSucceeded $event): void
    {
        $delivery = $this->resolveDelivery($event->data);

        if (! $delivery instanceof WebhookDelivery || $this->isTerminal($delivery)) {
            return;
        }

        // Outcome columns are guarded (engine-owned), so write them via forceFill.
        $written = $this->persist($delivery, [
            'status' => DeliveryStatus::Succeeded,
            'attempt' => $event->attempt,
            'response_code' => $event->response->status,
            'duration_ms' => $event->response->durationMs,
            'delivered_at' => now(),
            'error' => null,
        ]);

        if (! $written) {
            return;
        }

        $subscription = $delivery->subscription;

        if ($subscription->consecutive_failures > 0) {
            // Direct assignment: these columns are intentionally not mass-assignable.
            $subscription->consecutive_failures = 0;
            $subscription->save();
        }

        Event::dispatch(new WebhookDeliverySucceeded($delivery));
    }

    public function onFailed(WebhookAttemptFailed $event): void
    {
        $delivery = $this->resolveDelivery($event->data);

        // A failed attempt that will be retried. Leave terminal rows untouched so a
        // duplicated or out-of-order event cannot downgrade a success or an exhaustion.
        if (! $delivery instanceof WebhookDelivery || $this->isTerminal($delivery)) {
            return;
        }

        $this->persist($delivery, [
            'status' => DeliveryStatus::Failed,
            'attempt' => $event->attempt,
            'response_code' => $event->response?->status,
            'duration_ms' => $event->response?->durationMs,
            'error' => $this->errorFrom($event->exception),
        ]);
    }

    public function onFinalFailed(WebhookAttemptsExhausted $event): void
    {
        $delivery = $this->resolveDelivery($event->data);

        if (! $delivery instanceof WebhookDelivery || $this->isTerminal($delivery)) {
            return;
        }

        $reason = $this->errorFrom($event->exception);

        // A refusal is not an exhaustion, and the difference is the whole reason the guard
        // below exists. Written as Exhausted it was indistinguishable afterwards, so the health
        // score counted against the endpoint what the breaker three lines down refuses to.
        $written = $this->persist($delivery, [
            'status' => $event->exception instanceof DeliveryRefused
                ? DeliveryStatus::Refused
                : DeliveryStatus::Exhausted,
            'attempt' => $event->attempt,
            'response_code' => $event->response?->status,
            'duration_ms' => $event->response?->durationMs,
            'error' => $reason,
        ]);

        if (! $written) {
            return;
        }

        $subscription = $delivery->subscription;

        // Only a real failure of a LIVE endpoint feeds the breaker. A refused delivery was never
        // sent: its endpoint was disabled, deleted or given another URL while it waited. That is
        // our own decision, not the endpoint's fault. Charged to a disabled endpoint it would
        // trip the breaker again on the first hiccup after a re-enable; charged to one whose URL
        // just changed, every delivery still queued for the old address would count against the
        // new one, and a backlog as long as the threshold would switch it off.
        if ($subscription->is_active && ! $event->exception instanceof DeliveryRefused) {
            $subscription->increment('consecutive_failures');
            $subscription->refresh();

            $this->maybeAutoDisable($subscription);
        }

        Event::dispatch(new WebhookDeliveryFailed($delivery, $reason));
    }

    /**
     * Write the outcome columns and re-index the row for Scout. The log is updated through the
     * base model, which never fires Scout's per-subclass observer, so an external engine would
     * otherwise keep the stale (or missing) status; a no-op unless search is on and a searchable
     * source model is configured.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(WebhookDelivery $delivery, array $attributes): bool
    {
        // The caller read the row and found it unfinished a moment ago, but a duplicate of this
        // job on a second worker can have finished it since. The write carries the same check in
        // its WHERE, and only a write that landed is followed by what a status change sets off:
        // the counter reset, the breaker and the lifecycle events.
        if (! $delivery->forceFill($attributes)->saveUnlessTerminal()) {
            return false;
        }

        SearchIndexer::indexDelivery($delivery);

        return true;
    }

    private function maybeAutoDisable(WebhookSubscription $subscription): void
    {
        if (! $this->config->circuitBreakerEnabled()
            || $subscription->consecutive_failures < $this->config->circuitBreakerThreshold()) {
            return;
        }

        // Atomic transition — a conditional UPDATE gated on is_active=true means only
        // the worker that actually flips the flag fires the event, even when several
        // deliveries of the same subscription exhaust concurrently.
        //
        // disabled_at goes through the model's own conversion, as in WebhookManager::disable()
        // for the same column. An Eloquent builder update hands its values to the query builder
        // as they are, which binds a timestamp as a naive literal in the application zone, an
        // engine's offset away from the instant meant; only the updated_at it adds is converted.
        $flipped = WebhookSubscription::model()::query()
            ->whereKey($subscription->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'disabled_at' => $subscription->fromDateTime(now())]);

        if ($flipped === 1) {
            Event::dispatch(new WebhookEndpointAutoDisabled($subscription->refresh()));
        }
    }

    /**
     * The persisted text, with any credential in the failed URL taken out of it first.
     *
     * The redaction is this package's, not guzzle's, because guzzle's differs between the
     * two psr7 majors the composer constraint allows — see {@see ErrorMessageRedactor}.
     */
    private function errorFrom(?Throwable $exception): string
    {
        $message = $exception?->getMessage();

        return $message === null || $message === ''
            ? self::DEFAULT_ERROR
            : ErrorMessageRedactor::redact($message);
    }

    private function isTerminal(WebhookDelivery $delivery): bool
    {
        return $delivery->status->isTerminal();
    }

    private function resolveDelivery(WebhookDeliveryData $data): ?WebhookDelivery
    {
        $deliveryId = $data->meta['delivery_id'] ?? null;

        if (! is_string($deliveryId)) {
            return null;
        }

        $createdAt = $data->meta['delivery_created_at'] ?? null;

        // The log is PARTITIONED BY RANGE (created_at) and keyed by (id, created_at).
        // A lookup by id alone gives the planner nothing to prune with, so it probes the
        // primary-key index of every partition that exists — three times per delivery, on
        // the engine's hot path, and worse every month the log survives. The delivery
        // carries its own partition key, so the planner goes straight to the one
        // partition that can hold the row.
        $query = WebhookDelivery::model()::query()->whereKey($deliveryId);

        if (is_string($createdAt)) {
            $query->where('created_at', $createdAt);
        }

        $delivery = $query->first();

        if ($delivery instanceof WebhookDelivery || ! is_string($createdAt)) {
            return $delivery;
        }

        // Not on the parent: a partition drain may have moved the row into a month it has not
        // attached yet. The attempt was made either way, and dropping the write would leave the
        // log saying pending for good.
        return new PartitionManager()->movedDelivery($deliveryId, $createdAt);
    }
}
