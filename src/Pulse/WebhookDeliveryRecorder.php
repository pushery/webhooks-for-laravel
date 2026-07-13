<?php

declare(strict_types=1);

namespace Webhooks\Pulse;

use Laravel\Pulse\Pulse;
use Webhooks\Server\Events\WebhookAttemptsExhausted;
use Webhooks\Server\Events\WebhookAttemptSucceeded;

/**
 * Feeds each terminal delivery outcome into Laravel Pulse for the internal-ops card
 * (throughput, failure rate and latency). This is the single-view, gated engineering
 * monitor — distinct from the multi-tenant customer dashboard, which has its own read
 * model. It records nothing unless the opt-in {@see WebhookPulseServiceProvider} boots
 * it (pulse.enabled AND laravel/pulse installed), so a consumer without Pulse pays
 * nothing.
 *
 * Three Pulse entry types are written, all keyed by event type so the card can break
 * them down: a throughput count on every terminal delivery, a latency sample (avg and
 * max) whenever a response carried a duration, and a failure count on a final failure.
 * Failure rate is then failure-count over throughput-count.
 *
 * @internal
 */
final readonly class WebhookDeliveryRecorder
{
    /**
     * The throughput entry type: one count per terminal delivery.
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
