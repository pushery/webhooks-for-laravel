<?php

declare(strict_types=1);

namespace Webhooks\Pulse;

/**
 * One row of the internal-ops Pulse card's per-event-type breakdown: the throughput,
 * final-failure count and derived failure rate for an event type, plus its average and
 * maximum latency (null when no successful delivery recorded a duration).
 *
 * @internal
 */
final readonly class WebhookEventStat
{
    public function __construct(
        public string $event,
        public int $total,
        public int $failed,
        public float $failureRate,
        public ?float $avg,
        public ?float $max,
    ) {}
}
