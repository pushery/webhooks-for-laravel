<?php

declare(strict_types=1);

namespace Webhooks\Server\Telemetry;

/**
 * The default emitter: it deliberately does nothing. It is the bound implementation
 * while OpenTelemetry is off, so the package carries no tracing dependency and a
 * delivery emits no span until a host binds a real emitter and enables the seam.
 *
 * @internal
 */
final class NullSpanEmitter implements SpanEmitter
{
    public function emit(DeliverySpanAttributes $span): void
    {
        // Intentionally empty — the no-op default keeps the seam dependency-free.
    }
}
