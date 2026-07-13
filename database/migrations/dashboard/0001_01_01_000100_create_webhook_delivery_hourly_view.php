<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Webhooks\Dashboard\Metrics\TdigestExtension;
use Webhooks\Database\PostgresRequirement;

return new class extends Migration
{
    public function up(): void
    {
        PostgresRequirement::ensure($this->getConnection());

        // The hourly rollup that powers the dashboard's stacked-activity chart and
        // its latency-trend line. Counts are additive, so any window's KPI totals
        // are a sum over the hourly buckets; the per-hour p50/p95 drive the trend
        // line only — never a window-level percentile, which is computed live over
        // the raw rows (averaging per-hour percentiles is statistically wrong).
        //
        // The view is bounded to the last 35 days so a refresh never scans the full
        // history, and it is created WITH NO DATA: the schedule's
        // webhooks:refresh-metrics command populates it. Status filters map to the
        // real DeliveryStatus enum stored in webhook_deliveries.status: 'succeeded'
        // is a delivered attempt, and 'failed'/'exhausted' are both failures (a
        // subscription whose retries ran out). 'retried' counts attempts past the
        // first (the column is attempt, not attempts).
        //
        // A per-bucket latency_digest column is added only when the optional tdigest
        // extension is installed. It stores each hour's duration distribution as a
        // t-digest so the Tier-2 percentile driver can merge them across a window with
        // rollup() in O(buckets). The whole column is guarded behind the extension
        // check, so this migration still runs on a stock box without tdigest — the view
        // simply omits the digest and the default 'live' driver keeps working.
        $bucket = $this->bucketExpression();
        $digest = TdigestExtension::isInstalled($this->getConnection())
            ? ",\n                tdigest(duration_ms, 100)                                   AS latency_digest"
            : '';

        // Grouped by the WHOLE morph pair (owner_type, owner_id) — not owner_id alone —
        // so two tenants that share an owner_id under different owner types never share a
        // rollup row, and a window read that filters the pair sees only its own tenant.
        DB::statement(<<<SQL
            CREATE MATERIALIZED VIEW webhook_delivery_hourly AS
            SELECT
                owner_type,
                owner_id,
                {$bucket} AS bucket,
                count(*)                                                    AS total,
                count(*) FILTER (WHERE status = 'succeeded')                AS delivered,
                count(*) FILTER (WHERE status = 'pending')                  AS pending,
                count(*) FILTER (WHERE status IN ('failed', 'exhausted'))   AS failed,
                count(*) FILTER (WHERE attempt > 1)                         AS retried,
                percentile_cont(0.50) WITHIN GROUP (ORDER BY duration_ms)   AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms)   AS p95{$digest}
            FROM webhook_deliveries
            WHERE created_at >= now() - interval '35 days'
            GROUP BY owner_type, owner_id, bucket
            WITH NO DATA
            SQL);

        // Required for REFRESH ... CONCURRENTLY, and it makes the per-tenant window
        // reads an index scan rather than a full view scan. Keyed by the full morph
        // pair so it uniquely identifies each rollup row.
        DB::statement('CREATE UNIQUE INDEX webhook_delivery_hourly_uidx ON webhook_delivery_hourly (owner_type, owner_id, bucket)');

        // The first populate must be non-concurrent: CONCURRENTLY cannot run against
        // a view that has never held data.
        DB::statement('REFRESH MATERIALIZED VIEW webhook_delivery_hourly');
    }

    public function down(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS webhook_delivery_hourly');
    }

    /**
     * The hourly bucket expression over created_at. date_bin (PostgreSQL 14+) is the
     * primary path; on PostgreSQL 13 it does not exist, so an epoch-floor expression
     * yields the identical whole-hour boundary.
     */
    private function bucketExpression(): string
    {
        $connection = DB::connection($this->getConnection());
        $reported = $connection->scalar("SELECT current_setting('server_version_num')");

        // An unreadable version falls back to the epoch-floor expression, which yields the
        // same whole-hour boundary on every supported server.
        $version = is_numeric($reported) ? (int) $reported : 0;

        if ($version >= 140000) {
            return "date_bin('1 hour', created_at, TIMESTAMPTZ '2000-01-01')";
        }

        return 'to_timestamp(floor(extract(epoch FROM created_at) / 3600) * 3600)';
    }
};
