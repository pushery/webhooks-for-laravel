<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Dashboard\DashboardTenant;
use Pushery\Webhooks\Dashboard\Data\KpiSet;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Database\Dialect\Sql\PercentileSelect;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;
use RuntimeException;
use stdClass;

/**
 * The dashboard's reporting query object, scoped to one tenant and one time window.
 * It is deliberately Query Builder + justified raw SQL rather than a Repository:
 * percentile_cont, the materialized view and array-aggregate percentiles are not
 * expressible through Eloquent, and this abstracts analytics — not CRUD.
 *
 * The tenant is the WHOLE morph pair (owner_type, owner_id), never the id alone:
 * two tenants that share an owner_id under different owner types are different
 * tenants, so every read — the raw-row surface AND the hourly rollup — filters both
 * columns, or one tenant's counts, event types and delivery rows leak into another's.
 *
 * Counts come cheaply from the hourly rollup (additive, so any window is a sum of
 * buckets). The window-level latency percentiles are computed LIVE over the raw
 * rows in the bounded window — never averaged from the rollup's per-hour buckets,
 * which would be statistically wrong (see the Tier-1 percentile strategy).
 *
 * @internal
 */
final readonly class WebhookMetrics
{
    /**
     * The materialized view the hourly counts and trend are read from. It is grouped
     * by (owner_type, owner_id, bucket), so its reads scope the same morph pair as the
     * raw-row surface.
     */
    public const string HOURLY_VIEW = 'webhook_delivery_hourly';

    public function __construct(
        private DashboardTenant $tenant,
        private CarbonInterval $window,
    ) {}

    private function db(): ConnectionInterface
    {
        return WebhookConnection::db();
    }

    /**
     * Summed counts from the rollup plus the live window-level latency percentiles.
     */
    public function kpis(): KpiSet
    {
        return $this->buildKpiSet($this->percentiles());
    }

    /**
     * The counts-only KPIs: the summed rollup counts with the latency percentiles left
     * at zero. For a panel that renders the counts but never the percentiles (the KPI
     * ribbon), so a count refresh never pays for the window-level percentile sort — a
     * heavy query that is only needed by the latency panel.
     */
    public function counts(): KpiSet
    {
        return $this->buildKpiSet(['p50' => 0.0, 'p90' => 0.0, 'p95' => 0.0, 'p99' => 0.0]);
    }

    /**
     * Assemble a KpiSet from the summed rollup counts and the given percentiles. The
     * additive counts are always a cheap sum of the hourly buckets; the percentiles are
     * supplied by the caller so the counts-only path can skip computing them.
     *
     * @param  array{p50: float, p90: float, p95: float, p99: float}  $percentiles
     */
    private function buildKpiSet(array $percentiles): KpiSet
    {
        [$ownerSql, $ownerBindings] = $this->tenant->rollupCondition(WebhookConnection::dialect());

        $counts = (array) $this->db()->table(self::HOURLY_VIEW)
            ->whereRaw($ownerSql, $ownerBindings)
            ->where('bucket', '>=', $this->since())
            // Reordering this list changes nothing: the row is read back by column name below, so a
            // SELECT list in a different order returns the same KpiSet. There is no assertion that
            // could tell two orders apart, and none should be invented.
            //
            // The surface itself is covered, which is the half worth checking before believing
            // the paragraph above: DROP any one of these five aggregates and the metrics suite
            // goes red (measured). Only the reordering is free.
            ->selectRaw(
                'coalesce(sum(total), 0)     as total, '
                .'coalesce(sum(delivered), 0) as delivered, '
                .'coalesce(sum(pending), 0)   as pending, '
                .'coalesce(sum(failed), 0)    as failed, '
                .'coalesce(sum(retried), 0)   as retried'
            )
            ->first();

        // The five `?? 0` defaults below cannot be reached. The SELECT above wraps every aggregate
        // in coalesce(..., 0), so the row always carries the key and never carries null, and `??`
        // fires on null. All five moved to `?? 1` at once left the dashboard suites green.
        //
        // The control is the surface itself, one method up: drop any one of those five aggregates
        // from the SELECT and the suite goes red. This is a statement about the defaults, not about
        // an unmeasured KpiSet.
        //
        // They stay because KpiSet's constructor takes ints and this is the boundary where
        // that becomes true, rather than one call further in.
        return new KpiSet(
            total: $this->toInt($counts['total'] ?? 0),
            delivered: $this->toInt($counts['delivered'] ?? 0),
            pending: $this->toInt($counts['pending'] ?? 0),
            failed: $this->toInt($counts['failed'] ?? 0),
            retried: $this->toInt($counts['retried'] ?? 0),
            p50: $percentiles['p50'],
            p90: $percentiles['p90'],
            p95: $percentiles['p95'],
            p99: $percentiles['p99'],
        );
    }

    /**
     * The hourly rollup rows in the window, oldest first — the stacked-activity
     * bars and the latency-trend line.
     *
     * @return Collection<int, stdClass>
     */
    public function hourly(): Collection
    {
        [$ownerSql, $ownerBindings] = $this->tenant->rollupCondition(WebhookConnection::dialect());

        return $this->db()->table(self::HOURLY_VIEW)
            ->whereRaw($ownerSql, $ownerBindings)
            ->where('bucket', '>=', $this->since())
            ->orderBy('bucket')
            ->get(['bucket', 'total', 'delivered', 'pending', 'failed', 'retried', 'p50', 'p95']);
    }

    /**
     * How far the rollup has fallen behind the rows it summarizes, in seconds — or null when it
     * has not.
     *
     * The dashboard shows two kinds of number on one screen, and only one of them can go stale. The
     * counts are summed from the materialised rollup, which only `webhooks:refresh-metrics`
     * advances; the latency percentiles and the endpoint counts are computed live. Let the refresh
     * stop — a crashed cron, a mutex left behind by a hard kill, a thrown exception — and frozen
     * delivery counts sit next to current percentiles that make them look plausible. Nothing on the
     * screen said which was which.
     *
     * It is measured against the raw rows rather than against the clock, and that is what keeps it
     * quiet on a quiet installation. Comparing the newest rollup bucket to `now()` reports every
     * endpoint that simply had no traffic as stale; comparing it to the newest delivery reports
     * only the case where rows exist that the rollup has not seen. No traffic, no lag, no warning.
     *
     * Null has two causes and they are the same answer: nothing to summarize yet, or the rollup
     * is level with the rows. Neither is a problem to show anybody.
     */
    public function rollupLagSeconds(): ?int
    {
        [$ownerSql, $ownerBindings] = $this->tenant->rollupCondition(WebhookConnection::dialect());
        [$rawSql, $rawBindings] = $this->tenant->condition();

        $newestBucket = $this->db()->table(self::HOURLY_VIEW)
            ->whereRaw($ownerSql, $ownerBindings)
            ->max('bucket');

        $newestRow = $this->db()->table($this->sourceTable())
            ->whereRaw($rawSql, $rawBindings)
            ->max('created_at');

        if (! is_string($newestRow)) {
            return null;
        }

        $rowAt = CarbonImmutable::parse($newestRow);

        // Nothing in the rollup at all while rows exist: the whole lag is the age of the oldest
        // thing it should have seen, and the newest row is the cheapest honest floor for that.
        if (! is_string($newestBucket)) {
            return (int) max(0, CarbonImmutable::now()->diffInSeconds($rowAt, absolute: true));
        }

        $bucketAt = CarbonImmutable::parse($newestBucket);

        // The rollup buckets by hour, so it is up to an hour behind the newest row BY DESIGN.
        // Reporting that as lag would make a healthy installation warn once an hour.
        $lag = $rowAt->diffInSeconds($bucketAt->addHour(), absolute: false);

        if ($lag < 0) {
            return (int) abs($lag);
        }

        return null;
    }

    /**
     * The most frequent event types in the window, busiest first.
     *
     * @return Collection<int, stdClass>
     */
    public function topEvents(int $limit = 5): Collection
    {
        [$ownerSql, $ownerBindings] = $this->tenant->condition();

        return $this->db()->table($this->sourceTable())
            ->whereRaw($ownerSql, $ownerBindings)
            ->where('created_at', '>=', $this->since())
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->limit($this->rowBudget($limit))
            ->selectRaw('event_type, count(*) as total')
            ->get();
    }

    /**
     * The most recent deliveries for the owner, newest first — a live read, not
     * the rollup.
     *
     * @return EloquentCollection<int, WebhookDelivery>
     */
    public function recentQueue(int $limit = 5): EloquentCollection
    {
        [$ownerSql, $ownerBindings] = $this->tenant->condition();

        return $this->sourceModel()
            ->newQuery()
            // The endpoint each row's replay button names, fetched once for the panel rather
            // than once per row. Named columns only: a subscription carries its signing secret,
            // and nothing on this strip needs it.
            ->with(['subscription:id,name,url'])
            ->whereRaw($ownerSql, $ownerBindings)
            // The window the caller already asked for. Every other query here carries it;
            // this one did not, so a panel named "recent" would sort the owner's WHOLE
            // history to find its newest rows — and on the range-partitioned delivery table
            // that is a read the planner cannot prune, across every partition that exists.
            ->where('created_at', '>=', $this->since())
            ->orderByDesc('created_at')
            ->limit($this->rowBudget($limit))
            ->get();
    }

    /**
     * A row budget that is always a budget.
     *
     * Laravel's `limit()` DROPS a negative value in silence — `if ($value >= 0)` — and the
     * statement then goes out with no LIMIT clause at all. That turns a bounded panel query
     * into a full read of the owner's history, and both callers poll, so one open tab
     * repeats it for as long as it is open.
     *
     * Clamped here, on the parameter, rather than at each call site: the call sites are the
     * thing that will be added to, and a rule that has to be remembered at a new one is a
     * rule that will be missed at a new one.
     */
    private function rowBudget(int $limit): int
    {
        return max(1, min($limit, 100));
    }

    /**
     * The window-level latency percentiles, dispatched to the configured driver.
     * 'live' (Tier-1, default) computes them over the raw rows on stock PostgreSQL;
     * 'tdigest' (Tier-2) merges the per-bucket digests stored in the rollup, which
     * costs O(buckets) rather than O(rows) for high-volume tenants.
     *
     * @return array{p50: float, p90: float, p95: float, p99: float}
     */
    private function percentiles(): array
    {
        return match (Config::string('webhooks.dashboard.percentiles.driver', 'live')) {
            'tdigest' => $this->tdigestPercentiles(),
            default => $this->livePercentiles(),
        };
    }

    /**
     * Tier-1 percentiles: a single percentile_cont over the raw duration_ms values
     * in the bounded window. NULL durations are ignored by the aggregate; an empty
     * window yields zeros.
     *
     * @return array{p50: float, p90: float, p95: float, p99: float}
     */
    private function livePercentiles(): array
    {
        [$ownerSql, $ownerBindings] = $this->tenant->condition();

        $row = (array) $this->db()->selectOne(
            $this->livePercentileSql($ownerSql),
            [...$ownerBindings, $this->since()],
        );

        return [
            'p50' => $this->toFloat($row['p50'] ?? 0),
            'p90' => $this->toFloat($row['p90'] ?? 0),
            'p95' => $this->toFloat($row['p95'] ?? 0),
            'p99' => $this->toFloat($row['p99'] ?? 0),
        ];
    }

    /**
     * The window-level percentile query for the current dialect. PostgreSQL computes all four in
     * one percentile_cont(ARRAY[...]); MySQL reconstructs them with a single window-function pass.
     *
     * @param  literal-string  $ownerSql
     */
    private function livePercentileSql(string $ownerSql): string
    {
        $where = $ownerSql.' AND created_at >= ?';

        if (WebhookConnection::dialect() === Dialect::MySql) {
            return PercentileSelect::mysqlWindowMulti(
                ['p50' => 0.5, 'p90' => 0.9, 'p95' => 0.95, 'p99' => 0.99],
                $this->sourceTable(),
                $where,
            );
        }

        return 'SELECT pct[1] AS p50, pct[2] AS p90, pct[3] AS p95, pct[4] AS p99 FROM ('
            .'SELECT percentile_cont(ARRAY[0.5, 0.9, 0.95, 0.99]) WITHIN GROUP (ORDER BY duration_ms) AS pct '
            .'FROM '.$this->sourceTable().' WHERE '.$where
            .') s';
    }

    /**
     * Tier-2 percentiles: merge the per-bucket latency digests in the window with the
     * tdigest extension's rollup() and read the percentiles off the merged digest,
     * touching one row per hour rather than every raw delivery. Selecting this driver
     * without the extension installed is a hard, actionable error (never a cryptic SQL
     * failure); an empty window's rollup is NULL, so the percentiles fall back to zero.
     *
     * @return array{p50: float, p90: float, p95: float, p99: float}
     */
    private function tdigestPercentiles(): array
    {
        // Probe the extension on the WEBHOOK connection (where the tdigest SQL below runs),
        // not the app default: under a side-car topology the two differ, and checking the
        // wrong one either disables a supported feature or lets the SQL fail with the exact
        // missing-function error this guard exists to prevent.
        TdigestExtension::ensureInstalled(WebhookConnection::name());

        [$ownerSql, $ownerBindings] = $this->tenant->rollupCondition(WebhookConnection::dialect());

        $row = (array) $this->db()->selectOne(
            'WITH merged AS ('
            .'SELECT rollup(latency_digest) AS digest FROM '.self::HOURLY_VIEW.' '
            .'WHERE '.$ownerSql.' AND bucket >= ?'
            .') SELECT '
            .'tdigest_percentile(digest, 0.5)  AS p50, '
            .'tdigest_percentile(digest, 0.9)  AS p90, '
            .'tdigest_percentile(digest, 0.95) AS p95, '
            .'tdigest_percentile(digest, 0.99) AS p99 '
            .'FROM merged',
            [...$ownerBindings, $this->since()],
        );

        return [
            'p50' => $this->toFloat($row['p50'] ?? 0),
            'p90' => $this->toFloat($row['p90'] ?? 0),
            'p95' => $this->toFloat($row['p95'] ?? 0),
            'p99' => $this->toFloat($row['p99'] ?? 0),
        ];
    }

    /**
     * Coerce a raw database value (PostgreSQL returns numerics as strings) to int,
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

    /**
     * The inclusive lower bound of the window.
     */
    private function from(): CarbonImmutable
    {
        return CarbonImmutable::now()->sub($this->window);
    }

    /**
     * The window's lower bound as an unambiguous SQL literal. Every bound this class
     * compares against a timestamptz column goes through here: a naive literal would be
     * resolved against the database session's time zone, so the same window would mean
     * a different span of time depending on how the server is configured.
     */
    private function since(): string
    {
        return Timestamp::forDialect(WebhookConnection::dialect(), $this->from());
    }

    /**
     * A fresh instance of the configured read-surface model. It must be the delivery
     * log model (or a subclass of it): that model's documented columns are the
     * semver'd read surface the metrics are computed from.
     */
    private function sourceModel(): WebhookDelivery
    {
        $class = Config::string('webhooks.dashboard.source_model', WebhookDelivery::class);
        $model = Container::getInstance()->make($class);

        if (! $model instanceof WebhookDelivery) {
            throw new RuntimeException(
                "The configured webhooks.dashboard.source_model [{$class}] must be a "
                .WebhookDelivery::class.' or a subclass of it.'
            );
        }

        return $model;
    }

    /**
     * The read-surface table name, validated as a bare identifier before it is
     * interpolated into the percentile/top-events raw SQL.
     */
    private function sourceTable(): string
    {
        $table = $this->sourceModel()->getTable();

        if (preg_match('/^[A-Za-z_]\w*$/', $table) !== 1) {
            throw new RuntimeException(
                "Refusing to build metrics SQL for an unexpected source table name [{$table}]."
            );
        }

        return $table;
    }
}
