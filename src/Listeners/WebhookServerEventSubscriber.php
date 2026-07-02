<?php

declare(strict_types=1);

namespace Webhooks\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Spatie\WebhookServer\Events\FinalWebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallEvent;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Events\WebhookDeliveryFailed;
use Webhooks\Events\WebhookDeliverySucceeded;
use Webhooks\Events\WebhookEndpointAutoDisabled;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\WebhookConfig;

/**
 * Translates spatie's per-call events into delivery-log updates and drives the
 * circuit breaker. Every handler locates its row by the delivery id carried in
 * the call's meta and is idempotent: it never re-processes a delivery that is
 * already in a terminal state (Succeeded or Exhausted), so a duplicated or
 * out-of-order event — a known at-least-once queue edge case — can neither
 * downgrade a success nor double-count a failure.
 */
final readonly class WebhookServerEventSubscriber
{
    public function __construct(
        private WebhookConfig $config,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WebhookCallSucceededEvent::class, [self::class, 'onSucceeded']);
        $events->listen(WebhookCallFailedEvent::class, [self::class, 'onFailed']);
        $events->listen(FinalWebhookCallFailedEvent::class, [self::class, 'onFinalFailed']);
    }

    public function onSucceeded(WebhookCallSucceededEvent $event): void
    {
        $delivery = $this->resolveDelivery($event);

        if (! $delivery instanceof WebhookDelivery || $this->isTerminal($delivery)) {
            return;
        }

        $delivery->update([
            'status' => DeliveryStatus::Succeeded,
            'attempt' => $event->attempt,
            'response_code' => $event->response?->getStatusCode(),
            'response_ms' => $this->responseMs($event),
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

    public function onFailed(WebhookCallFailedEvent $event): void
    {
        $delivery = $this->resolveDelivery($event);

        // A failed attempt that will be retried. Leave terminal rows untouched so a
        // duplicated or out-of-order event cannot downgrade a success or an exhaustion.
        if (! $delivery instanceof WebhookDelivery || $this->isTerminal($delivery)) {
            return;
        }

        $delivery->update([
            'status' => DeliveryStatus::Failed,
            'attempt' => $event->attempt,
            'response_code' => $event->response?->getStatusCode(),
            'response_ms' => $this->responseMs($event),
            'error' => $event->errorMessage,
        ]);
    }

    public function onFinalFailed(FinalWebhookCallFailedEvent $event): void
    {
        $delivery = $this->resolveDelivery($event);

        if (! $delivery instanceof WebhookDelivery || $this->isTerminal($delivery)) {
            return;
        }

        $delivery->update([
            'status' => DeliveryStatus::Exhausted,
            'attempt' => $event->attempt,
            'response_code' => $event->response?->getStatusCode(),
            'response_ms' => $this->responseMs($event),
            'error' => $event->errorMessage,
        ]);

        $subscription = $delivery->subscription;
        $subscription->increment('consecutive_failures');
        $subscription->refresh();

        $this->maybeAutoDisable($subscription);

        Event::dispatch(new WebhookDeliveryFailed($delivery, $event->errorMessage ?? 'Webhook delivery failed.'));
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

    private function resolveDelivery(WebhookCallEvent $event): ?WebhookDelivery
    {
        $deliveryId = $event->meta['delivery_id'] ?? null;

        if (! is_string($deliveryId)) {
            return null;
        }

        return WebhookDelivery::query()->find($deliveryId);
    }

    private function responseMs(WebhookCallEvent $event): ?int
    {
        $seconds = $event->transferStats?->getTransferTime();

        return $seconds === null ? null : (int) round($seconds * 1000);
    }
}
