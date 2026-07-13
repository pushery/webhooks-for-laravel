<?php

declare(strict_types=1);

namespace Webhooks\Server\Telemetry;

use Webhooks\Server\Events\WebhookAttemptsExhausted;
use Webhooks\Server\Events\WebhookAttemptSucceeded;

/**
 * The pure, dependency-free mapping of a finished delivery to an OpenTelemetry span
 * name plus attribute bag. It knows nothing about any tracing SDK — it just names
 * the span and describes it, so an emitter can hand the values to whatever tracer a
 * host has bound. Fully testable in isolation.
 */
final readonly class DeliverySpanAttributes
{
    private const string SPAN_NAME = 'webhook.delivery';

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function __construct(
        public string $name,
        public array $attributes,
    ) {}

    public static function forSucceeded(WebhookAttemptSucceeded $event): self
    {
        return new self(self::SPAN_NAME, [
            'webhook.event_type' => $event->data->eventType,
            'webhook.status' => 'succeeded',
            'webhook.attempt' => $event->attempt,
            'webhook.duration_ms' => $event->response->durationMs,
            'http.status_code' => $event->response->status,
        ]);
    }

    public static function forFinalFailure(WebhookAttemptsExhausted $event): self
    {
        return new self(self::SPAN_NAME, [
            'webhook.event_type' => $event->data->eventType,
            'webhook.status' => 'failed',
            'webhook.attempt' => $event->attempt,
            'webhook.duration_ms' => $event->response?->durationMs,
            'http.status_code' => $event->response?->status,
        ]);
    }
}
