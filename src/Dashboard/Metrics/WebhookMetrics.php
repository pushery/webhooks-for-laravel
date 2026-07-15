<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use stdClass;
use Webhooks\Dashboard\Data\KpiSet;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\Dialect\Sql\PercentileSelect;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Support\TenantIdentity;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

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
        private TenantIdentity $owner,
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
        $counts = (array) $this->db()->table(self::HOURLY_VIEW)
            ->where('owner_type', $this->owner->type)
            ->where('owner_id', $this->owner->id)
            ->where('bucket', '>=', $this->since())
            ->selectRaw(
                'coalesce(sum(total), 0)     as total, '
                .'coalesce(sum(delivered), 0) as delivered, '
                .'coalesce(sum(pending), 0)   as pending, '
                .'coalesce(sum(failed), 0)    as failed, '
                .'coalesce(sum(retried), 0)   as retried'
            )
            ->first();

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
        return $this->db()->table(self::HOURLY_VIEW)
            ->where('owner_type', $this->owner->type)
            ->where('owner_id', $this->owner->id)
            ->where('bucket', '>=', $this->since())
            ->orderBy('bucket')
            ->get(['bucket', 'total', 'delivered', 'pending', 'failed', 'retried', 'p50', 'p95']);
    }

    /**
     * The most frequent event types in the window, busiest first.
     *
     * @return Collection<int, stdClass>
     */
    public function topEvents(int $limit = 5): Collection
    {
        return $this->db()->table($this->sourceTable())
            ->where('owner_type', $this->owner->type)
            ->where('owner_id', $this->owner->id)
            ->where('created_at', '>=', $this->since())
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->limit($limit)
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
        return $this->sourceModel()
            ->newQuery()
            ->where('owner_type', $this->owner->type)
            ->where('owner_id', $this->owner->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
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
        $row = (array) $this->db()->selectOne(
            $this->livePercentileSql(),
            [$this->owner->type, $this->owner->id, $this->since()],
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
     */
    private function livePercentileSql(): string
    {
        $where = 'owner_type = ? AND owner_id = ? AND created_at >= ?';

        if (Dialect::for() === Dialect::MySql) {
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
        TdigestExtension::ensureInstalled();

        $row = (array) $this->db()->selectOne(
            'WITH merged AS ('
            .'SELECT rollup(latency_digest) AS digest FROM '.self::HOURLY_VIEW.' '
            .'WHERE owner_type = ? AND owner_id = ? AND bucket >= ?'
            .') SELECT '
            .'tdigest_percentile(digest, 0.5)  AS p50, '
            .'tdigest_percentile(digest, 0.9)  AS p90, '
            .'tdigest_percentile(digest, 0.95) AS p95, '
            .'tdigest_percentile(digest, 0.99) AS p99 '
            .'FROM merged',
            [$this->owner->type, $this->owner->id, $this->since()],
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
        return Dialect::for() === Dialect::MySql
            ? Timestamp::mysql($this->from())
            : Timestamp::sql($this->from());
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
