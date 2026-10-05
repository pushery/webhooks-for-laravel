<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Pulse;

use Laravel\Pulse\Pulse;
use Pushery\Webhooks\Server\Events\WebhookAttemptsExhausted;
use Pushery\Webhooks\Server\Events\WebhookAttemptSucceeded;
use Pushery\Webhooks\Server\Exceptions\DeliveryRefused;

/**
 * Feeds each terminal delivery outcome into Laravel Pulse for the internal-ops card
 * (throughput, failure rate and latency). This is the single-view, gated engineering
 * monitor — distinct from the multi-tenant customer dashboard, which has its own read
 * model. It records nothing unless the opt-in {@see WebhookPulseServiceProvider} boots
 * it (pulse.enabled AND laravel/pulse installed), so a consumer without Pulse pays
 * nothing.
 *
 * Four Pulse entry types are written, all keyed by event type so the card can break
 * them down: a throughput count on every delivery that was attempted and reached its final
 * outcome, a latency sample (avg and max) whenever a response carried a duration, a failure
 * count on a final failure, and a refusal count on a delivery that was never sent.
 * Failure rate is then failure-count over throughput-count, the rate the circuit breaker,
 * the endpoint health score and DeliveryEngineCheck read.
 *
 * @internal
 */
final readonly class WebhookDeliveryRecorder
{
    /**
     * The throughput entry type: one count per delivery that was attempted and reached its
     * final outcome.
     */
    public const string THROUGHPUT = 'webhook_throughput';

    /**
     * The latency entry type: the delivery duration in milliseconds (avg + max).
     */
    public const string LATENCY = 'webhook_latency';

    /**
     * The failure entry type: one count per final (non-retryable / exhausted) failure.
     */
    public const string FAILURE = 'webhook_failure';

    /**
     * The refusal entry type: one count per delivery refused before it was sent, because its
     * endpoint was switched off or deleted while it waited in the queue.
     */
    public const string REFUSED = 'webhook_refused';

    /**
     * The key used when a delivery carries no event type.
     */
    public const string UNKNOWN_EVENT = '(unknown)';

    /**
     * The delivery events this recorder ingests, mirroring the array Pulse's own
     * recorders expose so the provider can wire it the same way.
     *
     * @var list<class-string>
     */
    public array $listen;

    public function __construct(
        private Pulse $pulse,
    ) {
        $this->listen = [
            WebhookAttemptSucceeded::class,
            WebhookAttemptsExhausted::class,
        ];
    }

    /**
     * Record one terminal delivery outcome. A success always carries a duration; a
     * final failure may not (a blocked destination or a connection error never got a
     * response), in which case the latency sample is skipped.
     */
    public function record(WebhookAttemptSucceeded|WebhookAttemptsExhausted $event): void
    {
        $type = $event->data->eventType ?? self::UNKNOWN_EVENT;

        // A refused delivery was never sent, so it says nothing about any destination. Counted
        // as throughput and as a failure, every delivery queued for an endpoint somebody switched
        // off raised the failure rate; it is counted on its own instead, so it stays visible.
        if ($event instanceof WebhookAttemptsExhausted && $event->exception instanceof DeliveryRefused) {
            $this->pulse->record(self::REFUSED, $type)->count();

            return;
        }

        // The split changes no value: both arms read the same property, and a
        // succeeded attempt always carries a response, so the nullsafe arm answers identically
        // for it. The split exists for the TYPE CHECKER — on the succeeded event the response
        // is non-nullable, and reading it nullsafe there would widen the result to null for a
        // case that cannot happen.
        $durationMs = $event instanceof WebhookAttemptSucceeded
            ? $event->response->durationMs
            : $event->response?->durationMs;

        $this->pulse->record(self::THROUGHPUT, $type)->count();

        if ($durationMs !== null) {
            $this->pulse->record(self::LATENCY, $type, $durationMs)->avg()->max();
        }

        if ($event instanceof WebhookAttemptsExhausted) {
            $this->pulse->record(self::FAILURE, $type)->count();
        }
    }
}
