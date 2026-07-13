<?php

declare(strict_types=1);

namespace Webhooks\Platform\Health;

use Illuminate\Support\Facades\DB;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\Settings;
use Webhooks\Support\Timestamp;

/**
 * Scores the health of a webhook endpoint from its own recent delivery history.
 *
 * The score is a single 0-100 number blended from three signals over a bounded
 * window: the success rate (succeeded over resolved deliveries), a penalty as p95
 * latency approaches a budget, and a penalty as the endpoint's consecutive-failure
 * streak grows. The three signal weights, the window, the latency budget and the
 * streak ceiling are all configurable under webhooks.platform.health.
 *
 * The history read is a single aggregate query scoped to one subscription_id, so
 * scoring many endpoints never fans out into per-row queries. An endpoint with no
 * resolved deliveries in the window has nothing to score and reports Unknown.
 */
final readonly class EndpointHealth
{
    public function __construct(
        private Settings $config,
    ) {}

    /**
     * Compute the current health of a single endpoint from its recent history.
     */
    public function scoreFor(WebhookSubscription $subscription): HealthReport
    {
        $window = $this->config->healthWindowHours();
        $since = Timestamp::sql(now()->subHours($window));

        // One pass over the subscription's recent deliveries: how many resolved
        // (reached an attempted outcome, so pending in-flight rows do not count),
        // how many of those succeeded, and the p95 of their measured durations.
        // percentile_cont ignores NULL durations, so an unmeasured row is skipped.
        $row = (array) DB::selectOne(
            'SELECT '
            ."count(*) FILTER (WHERE status IN ('succeeded', 'failed', 'exhausted')) AS resolved, "
            ."count(*) FILTER (WHERE status = 'succeeded') AS succeeded, "
            .'percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95 '
            .'FROM webhook_deliveries '
            .'WHERE subscription_id = ? AND created_at >= ?',
            [$subscription->id, $since],
        );

        $resolved = $this->toInt($row['resolved'] ?? 0);

        if ($resolved === 0) {
            return HealthReport::unknown();
        }

        $succeeded = $this->toInt($row['succeeded'] ?? 0);
        $p95 = $this->toFloat($row['p95'] ?? 0);
        $successRate = $succeeded / $resolved;

        $score = $this->composeScore($successRate, $p95, $subscription->consecutive_failures);

        return new HealthReport(
            score: $score,
            status: HealthStatus::fromScore($score),
            successRate: $successRate,
            p95: $p95,
            sampleSize: $resolved,
        );
    }

    /**
     * Compute the health and write the cached columns onto the subscription.
     */
    public function refresh(WebhookSubscription $subscription): HealthReport
    {
        $report = $this->scoreFor($subscription);

        $this->persist($subscription, $report);

        return $report;
    }

    /**
     * Write a computed report onto the subscription's cached health columns. These
     * columns are intentionally not mass-assignable, so they are set directly.
     */
    public function persist(WebhookSubscription $subscription, HealthReport $report): void
    {
        $subscription->health_score = $report->score;
        $subscription->health_status = $report->status->value;
        $subscription->health_calculated_at = now();
        $subscription->save();
    }

    /**
     * Blend the three health signals into a 0-100 score using the configured weights.
     * Each signal is normalised to 0..1 (1 = perfectly healthy) and the weighted mean
     * is scaled to 0-100, so the score never leaves the range regardless of weights.
     */
    private function composeScore(float $successRate, float $p95, int $consecutiveFailures): int
    {
        $weights = $this->config->healthWeights();
        $total = $weights['success'] + $weights['latency'] + $weights['consecutive'];

        if ($total <= 0.0) {
            return (int) round($this->clampUnit($successRate) * 100);
        }

        $successSignal = $this->clampUnit($successRate);
        $latencySignal = $this->latencySignal($p95);
        $consecutiveSignal = $this->consecutiveSignal($consecutiveFailures);

        $blended = (
            $weights['success'] * $successSignal
            + $weights['latency'] * $latencySignal
            + $weights['consecutive'] * $consecutiveSignal
        ) / $total;

        return (int) round($this->clampUnit($blended) * 100);
    }

    /**
     * The latency signal falls linearly from 1 (instant) to 0 as p95 reaches the
     * configured budget, and stays at 0 beyond it.
     */
    private function latencySignal(float $p95): float
    {
        $budget = (float) $this->config->healthLatencyBudgetMs();

        if ($budget <= 0.0) {
            return 1.0;
        }

        return $this->clampUnit(1.0 - ($p95 / $budget));
    }

    /**
     * The consecutive-failure signal falls linearly from 1 (no streak) to 0 once the
     * streak reaches the configured ceiling.
     */
    private function consecutiveSignal(int $consecutiveFailures): float
    {
        $ceiling = $this->config->healthConsecutivePenaltyAt();

        if ($ceiling <= 0) {
            return $consecutiveFailures > 0 ? 0.0 : 1.0;
        }

        return $this->clampUnit(1.0 - ($consecutiveFailures / $ceiling));
    }

    private function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * Coerce a raw database value (PostgreSQL returns aggregates as strings) to int,
     * defaulting to zero for a null or non-numeric value.
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Coerce a raw database value to float, defaulting to zero for a null or
     * non-numeric value (an empty window yields a NULL percentile).
     */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
