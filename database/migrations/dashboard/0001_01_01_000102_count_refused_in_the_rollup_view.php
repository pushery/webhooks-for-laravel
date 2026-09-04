<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Pushery\Webhooks\Dashboard\Metrics\TdigestExtension;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Rebuilds `webhook_delivery_hourly` on an EXISTING PostgreSQL install so a refused delivery is
 * counted as a failure instead of falling into no bucket at all.
 *
 * The fix was half-applied, and the half that was missing is the default engine. When `refused` was
 * added to the delivery statuses, the MySQL rollup query (`RollupRefresh::mysql()`) gained it and
 * the PostgreSQL materialized view did not — so on PostgreSQL a refused delivery counted in `total`
 * and in none of delivered, pending or failed. The dashboard sums those three columns straight out
 * of this view, so its own numbers stopped adding up, with nothing on the screen saying which one
 * was short.
 *
 * The create migration carries the corrected filter now, which covers a fresh install and
 * nothing else: a host that already migrated never re-runs it. Without this file the fix would
 * reach new installations only.
 *
 * PostgreSQL only. The MySQL rollup is a query rather than a stored object, so its half needed
 * no migration at all — which is exactly why the two came apart.
 *
 * Nothing is at risk: every row of this view is derived from the delivery log, and
 * `webhooks:refresh-metrics` rebuilds it on its schedule. The view is dropped and recreated here,
 * and this migration REFRESHES it before it returns — a recreated materialized view is not merely
 * empty, every SELECT against it raises 55000 until something populates it, so leaving that to
 * the next scheduled run would take the dashboard down for the whole interval. The reasoning is
 * spelled out at the statement itself.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        // Default-deny, like every migration in this package: one that guards with neither
        // requirement fails with a raw driver error on the wrong engine.
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) !== Dialect::Pgsql) {
            return;
        }

        $connection = DB::connection($this->getConnection());

        // A host that has not run the create migration yet has nothing to correct — it will get
        // the fixed definition from that file directly.
        $exists = $connection->scalar("SELECT to_regclass('webhook_delivery_hourly')");

        if ($exists === null) {
            return;
        }

        // The digest column is optional and follows the extension, exactly as in the create
        // migration: a box without tdigest keeps a view without the column, and rebuilding must
        // not silently add or drop it.
        $digest = TdigestExtension::isInstalled($this->getConnection())
            ? ",\n                tdigest(duration_ms, 100)                                   AS latency_digest"
            : '';

        $bucket = $this->bucketExpression();

        $connection->statement('DROP MATERIALIZED VIEW IF EXISTS webhook_delivery_hourly');

        $connection->statement(<<<SQL
            CREATE MATERIALIZED VIEW webhook_delivery_hourly AS
            SELECT
                owner_type,
                owner_id,
                {$bucket} AS bucket,
                count(*)                                                    AS total,
                count(*) FILTER (WHERE status = 'succeeded')                AS delivered,
                count(*) FILTER (WHERE status = 'pending')                  AS pending,
                count(*) FILTER (WHERE status IN ('failed', 'exhausted', 'refused')) AS failed,
                count(*) FILTER (WHERE attempt > 1)                         AS retried,
                percentile_cont(0.50) WITHIN GROUP (ORDER BY duration_ms)   AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms)   AS p95{$digest}
            FROM webhook_deliveries
            WHERE created_at >= now() - interval '35 days'
            GROUP BY owner_type, owner_id, bucket
            WITH NO DATA
            SQL);

        // The unique index is what REFRESH ... CONCURRENTLY requires, so it has to come back
        // with the view. Dropping the view dropped it too.
        $connection->statement(
            'CREATE UNIQUE INDEX webhook_delivery_hourly_uidx ON webhook_delivery_hourly (owner_type, owner_id, bucket)',
        );

        // And populate it, non-concurrently, before this migration returns. A materialized view
        // recreated with no data is not merely empty: every SELECT against it raises 55000 "has not
        // been populated" until something refreshes it. Leaving that to the scheduled
        // webhooks:refresh-metrics would take the dashboard down for the whole interval between the
        // migration and the next run — an outage introduced by a migration whose entire purpose is
        // to correct a counting error.
        //
        // CONCURRENTLY cannot be used here for the same reason the create migration cannot
        // use it: it refuses to run against a view that has never held data.
        $connection->statement('REFRESH MATERIALIZED VIEW webhook_delivery_hourly');
    }

    /**
     * Deliberately empty. Down would put back a definition under which the dashboard's own
     * columns do not sum — a state this package should not be able to reach on purpose.
     */
    public function down(): void {}

    /**
     * The same bucket expression the create migration uses, for the same reason: an explicit UTC
     * origin keeps every engine's buckets on the hour whatever the session time zone is.
     */
    private function bucketExpression(): string
    {
        $connection = DB::connection($this->getConnection());
        $reported = $connection->scalar("SELECT current_setting('server_version_num')");
        $version = is_numeric($reported) ? (int) $reported : 0;

        if ($version >= 140000) {
            return "date_bin('1 hour', created_at, TIMESTAMPTZ '2000-01-01 00:00:00+00')";
        }

        return 'to_timestamp(floor(extract(epoch FROM created_at) / 3600) * 3600)';
    }
};
