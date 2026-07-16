<?php

declare(strict_types=1);

namespace Webhooks\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Events\WebhookDeliveryFailed;
use Webhooks\Events\WebhookDeliverySucceeded;
use Webhooks\Events\WebhookEndpointAutoDisabled;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Search\SearchIndexer;
use Webhooks\Server\Data\WebhookDeliveryData;
use Webhooks\Server\Events\WebhookAttemptFailed;
use Webhooks\Server\Events\WebhookAttemptsExhausted;
use Webhooks\Server\Events\WebhookAttemptSucceeded;
use Webhooks\Support\Settings;

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
        $this->persist($delivery, [
            'status' => DeliveryStatus::Succeeded,
            'attempt' => $event->attempt,
            'response_code' => $event->response->status,
            'duration_ms' => $event->response->durationMs,
            'delivered_at' => now(),
            'error' => null,
        ]);

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
            'error' => $event->exception?->getMessage() ?? self::DEFAULT_ERROR,
        ]);
    }

    public function onFinalFailed(WebhookAttemptsExhausted $event): void
    {
        $delivery = $this->resolveDelivery($event->data);

        if (! $delivery instanceof WebhookDelivery || $this->isTerminal($delivery)) {
            return;
        }

        $reason = $event->exception?->getMessage() ?? self::DEFAULT_ERROR;

        $this->persist($delivery, [
            'status' => DeliveryStatus::Exhausted,
            'attempt' => $event->attempt,
            'response_code' => $event->response?->status,
            'duration_ms' => $event->response?->durationMs,
            'error' => $reason,
        ]);

        $subscription = $delivery->subscription;

        // Only a real failure of a LIVE endpoint feeds the breaker. A delivery refused
        // because its endpoint is already disabled (or deleted) is our own decision, not
        // the endpoint's fault, and charging it would inflate the streak of an endpoint
        // that is not even being called — so a re-enabled endpoint would trip the breaker
        // again on its first hiccup.
        if ($subscription->is_active) {
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
    private function persist(WebhookDelivery $delivery, array $attributes): void
    {
        $delivery->forceFill($attributes)->save();

        SearchIndexer::indexDelivery($delivery->id);
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
        $flipped = WebhookSubscription::query()
            ->whereKey($subscription->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'disabled_at' => now()]);

        if ($flipped === 1) {
            Event::dispatch(new WebhookEndpointAutoDisabled($subscription->refresh()));
        }
    }

    private function isTerminal(WebhookDelivery $delivery): bool
    {
        return in_array($delivery->status, [DeliveryStatus::Succeeded, DeliveryStatus::Exhausted], true);
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
        $query = WebhookDelivery::query()->whereKey($deliveryId);

        if (is_string($createdAt)) {
            $query->where('created_at', $createdAt);
        }

        return $query->first();
    }
}
