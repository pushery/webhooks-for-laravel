<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Health;

use Illuminate\Database\ConnectionInterface;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Database\Dialect\Sql\ConditionalCount;
use Pushery\Webhooks\Database\Dialect\Sql\PercentileSelect;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;

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
        $dialect = WebhookConnection::dialect();

        // The window bound is bound for the ENGINE, not just the query shape. MySQL converts an
        // offset-bearing literal into the database SESSION time zone (8.0.19+), which silently
        // slides the whole window by that offset against the UTC-naive DATETIME(6) column — an
        // endpoint's oldest hours drop out and a failing endpoint can score healthy. The naive
        // form is the instant the column actually holds.
        $since = Timestamp::forDialect($dialect, now()->subHours($window));

        // One pass over the subscription's recent deliveries: how many resolved
        // (reached an attempted outcome, so pending in-flight rows do not count),
        // how many of those succeeded, and the p95 of their measured durations.
        [$resolved, $succeeded, $p95] = $dialect === Dialect::MySql
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
            // 'refused' is absent on purpose, and adding it would re-open a closed defect. A
            // refused delivery was never sent: the endpoint was disabled or deleted while it sat in
            // the queue, so it answered nothing and nothing about its health can be read from the
            // row. Counted here, the whole backlog the circuit breaker's own shutdown produced
            // landed in the denominator — the very deliveries the breaker declines to charge to the
            // failure streak, because refusing is our decision and not the endpoint's fault. So
            // re-enabling an endpoint reset the streak and left this score depressed for the rest
            // of the health window, with every delivery since successful.
            //
            // 'failed', by contrast, belongs here: a row sits there between attempts, and the set
            // is "reached an attempted outcome" as opposed to pending, not "terminal". The same row
            // flips to succeeded or exhausted when it resolves, so nothing is counted twice;
            // dropping it would silently take every first-attempt give-up out too.
            .ConditionalCount::of("status IN ('succeeded', 'failed', 'exhausted')").' AS resolved, '
            .ConditionalCount::of("status = 'succeeded'").' AS succeeded, '
            // The 0.95 here and its MySQL twin below are equivalent by construction rather than by
            // accident: PercentileSelect::fraction() renders the fraction from a fixed set of
            // literals so nothing interpolates into SQL, and its `default` arm collapses every
            // unsupported value back to '0.95'. Changing this constant therefore produces the
            // identical SQL.
            //
            // That fallback is deliberate, has its own test, and is already written up in
            // EndpointHealthTest beside the arm that would otherwise look like the one to blame.
            // Pointed at rather than restated, so there is one place to change if it ever stops
            // being true.
            .PercentileSelect::pgsqlExpression(0.95).' AS p95 '
            .'FROM webhook_deliveries '
            .'WHERE subscription_id = ? AND created_at >= ?',
            [$subscriptionId, $since],
        );

        // The two COUNT defaults on the next line cannot be reached. `??` fires on null, and a
        // COUNT never returns null — it returns 0 for a window with nothing in it — so no query
        // result gets that far. Moved to `?? 1` one at a time, the suite stayed green for both.
        //
        // The p95 default beside them is a different matter and is reachable: percentile_cont over
        // no rows returns NULL, which is exactly the empty-window case. Moving that one goes red,
        // and it is what makes the sentence above a claim about the two counts rather than about an
        // untested method.
        //
        // All three stay: the tuple's declared type has no nulls in it, and the defaults are what
        // make that true at the boundary instead of one call further in.
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
            // Same set as the Postgres arm above, including the deliberate absence of
            // 'refused' — the reasoning is written out there rather than twice.
            .ConditionalCount::of("status IN ('succeeded', 'failed', 'exhausted')").' AS resolved, '
            .ConditionalCount::of("status = 'succeeded'").' AS succeeded '
            .'FROM webhook_deliveries WHERE '.$where,
            [$subscriptionId, $since],
        );

        $p95 = (array) $this->db()->selectOne(
            PercentileSelect::mysqlQuery(0.95, 'webhook_deliveries', $where),
            [$subscriptionId, $since],
        );

        // The three defaults on the next line are there for the same reason as their twins in
        // readPostgres() above, which states it in full: the two counts cannot be reached, the p95
        // can, and all three make the tuple's declared type true at the boundary.
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
     * Each signal is normalized to 0..1 (1 = perfectly healthy) and the weighted mean
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
        // The cast changes no outcome: the getter already returns a number and the arithmetic below
        // works either way. It stays because this method's parameter and return are floats, and
        // mixing an int budget into that reads as an oversight.
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

        // `<= 0` and `<= 1` agree for every input at a ceiling of exactly 1: the shortcut answers
        // 0.0 for any streak and 1.0 for none, and the linear form below computes clampUnit(1 -
        // failures/1), which is the same two numbers.
        //
        // The shortcut stays for the ceiling it is actually there for — zero, where the division
        // below would be by zero.
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
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Coerce a raw database value to float, defaulting to zero for a null or
     * non-numeric value (an empty window yields a NULL percentile).
     */
    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
