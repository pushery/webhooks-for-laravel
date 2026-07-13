<?php

declare(strict_types=1);

namespace Webhooks\Server\Telemetry;

/**
 * The seam between a finished delivery and a tracer. The package binds a no-op
 * {@see NullSpanEmitter} by default, so nothing is emitted and no tracing SDK is
 * required. A host that wants OpenTelemetry traces binds its own implementation
 * that forwards the {@see DeliverySpanAttributes} to its OpenTelemetry tracer.
 */
interface SpanEmitter
{
    public function emit(DeliverySpanAttributes $span): void;
}
