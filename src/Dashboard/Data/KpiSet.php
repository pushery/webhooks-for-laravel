<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Data;

/**
 * The summarised delivery KPIs for one owner over one window: additive counts
 * summed from the hourly rollup, plus the window-level latency percentiles
 * computed live over the raw rows (never averaged from the rollup's per-hour
 * buckets). The retry rate is derived from the counts so the math lives in one
 * place.
 *
 * @internal
 */
final readonly class KpiSet
{
    public function __construct(
        public int $total,
        public int $delivered,
        public int $pending,
        public int $failed,
        public int $retried,
        public float $p50,
        public float $p90,
        public float $p95,
        public float $p99,
    ) {}

    /**
     * The share of deliveries that needed more than one attempt, as a percentage
     * rounded to one decimal. Zero when there were no deliveries in the window.
     */
    public function retryRate(): float
    {
        if ($this->total === 0) {
            return 0.0;
        }

        return round($this->retried / $this->total * 100, 1);
    }
}
