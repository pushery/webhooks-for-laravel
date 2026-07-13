<?php

declare(strict_types=1);

namespace Webhooks\Server\Telemetry;

use Webhooks\Server\Events\WebhookAttemptsExhausted;
use Webhooks\Server\Events\WebhookAttemptSucceeded;

/**
 * The listener that turns a finished delivery into a span and hands it to the bound
 * {@see SpanEmitter}. It is registered on the delivery events only while the
 * OpenTelemetry seam is enabled, so with the seam off it is never invoked.
 *
 * @internal
 */
final readonly class RecordDeliverySpan
{
    public function __construct(private SpanEmitter $emitter) {}

    public function onSucceeded(WebhookAttemptSucceeded $event): void
    {
        $this->emitter->emit(DeliverySpanAttributes::forSucceeded($event));
    }

    public function onFinalFailure(WebhookAttemptsExhausted $event): void
    {
        $this->emitter->emit(DeliverySpanAttributes::forFinalFailure($event));
    }
}
