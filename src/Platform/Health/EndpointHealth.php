<?php

declare(strict_types=1);

namespace Webhooks\Platform\Health;

use Illuminate\Database\ConnectionInterface;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\Dialect\Sql\ConditionalCount;
use Webhooks\Database\Dialect\Sql\PercentileSelect;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\Settings;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

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

    private function db(): ConnectionInterface
    {
        return WebhookConnection::db();
    }

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
        [$resolved, $succeeded, $p95] = WebhookConnection::dialect() === Dialect::MySql
            ? $this->readMySql($subscription->id, $since)
            : $this->readPostgres($subscription->id, $since);

        if ($resolved === 0) {
            return HealthReport::unknown();
        }

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
     * PostgreSQL reads the counts and the interpolated p95 in one pass: percentile_cont sits
     * inline beside the counts and ignores NULL durations for free.
     *
     * @return array{0: int, 1: int, 2: float}
     */
    private function readPostgres(int $subscriptionId, string $since): array
    {
        $row = (array) $this->db()->selectOne(
            'SELECT '
            .ConditionalCount::of("status IN ('succeeded', 'failed', 'exhausted')").' AS resolved, '
            .ConditionalCount::of("status = 'succeeded'").' AS succeeded, '
            .PercentileSelect::pgsqlExpression(0.95).' AS p95 '
            .'FROM webhook_deliveries '
            .'WHERE subscription_id = ? AND created_at >= ?',
            [$subscriptionId, $since],
        );

        return [$this->toInt($row['resolved'] ?? 0), $this->toInt($row['succeeded'] ?? 0), $this->toFloat($row['p95'] ?? 0)];
    }

    /**
     * MySQL has no ordered-set aggregate, so the p95 is a separate window-function query; the
     * counts stay portable. Both read the same window, and the interpolated p95 matches
     * PostgreSQL's percentile_cont to the last decimal.
     *
     * @return array{0: int, 1: int, 2: float}
     */
    private function readMySql(int $subscriptionId, string $since): array
    {
        $where = 'subscription_id = ? AND created_at >= ?';

        $counts = (array) $this->db()->selectOne(
            'SELECT '
            .ConditionalCount::of("status IN ('succeeded', 'failed', 'exhausted')").' AS resolved, '
            .ConditionalCount::of("status = 'succeeded'").' AS succeeded '
            .'FROM webhook_deliveries WHERE '.$where,
            [$subscriptionId, $since],
        );

        $p95 = (array) $this->db()->selectOne(
            PercentileSelect::mysqlQuery(0.95, 'webhook_deliveries', $where),
            [$subscriptionId, $since],
        );

        return [$this->toInt($counts['resolved'] ?? 0), $this->toInt($counts['succeeded'] ?? 0), $this->toFloat($p95['p95'] ?? 0)];
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
