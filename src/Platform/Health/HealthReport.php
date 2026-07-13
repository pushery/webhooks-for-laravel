<?php

declare(strict_types=1);

namespace Webhooks\Platform\Health;

/**
 * The computed health of a single endpoint over a bounded recent window: the 0-100
 * score (null when there is no history to score), its coarse status band, the raw
 * success rate and p95 latency the score was built from, and the number of resolved
 * deliveries the sample covered.
 */
final readonly class HealthReport
{
    public function __construct(
        public ?int $score,
        public HealthStatus $status,
        public float $successRate,
        public float $p95,
        public int $sampleSize,
    ) {}

    /**
     * A report for an endpoint with no recent resolved deliveries: nothing to score,
     * so the status is Unknown and every metric is zero.
     */
    public static function unknown(): self
    {
        return new self(
            score: null,
            status: HealthStatus::Unknown,
            successRate: 0.0,
            p95: 0.0,
            sampleSize: 0,
        );
    }
}
