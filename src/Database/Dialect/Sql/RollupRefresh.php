<?php

declare(strict_types=1);

namespace Webhooks\Database\Dialect\Sql;

use Webhooks\Database\OwnerKeyType;

/**
 * Recomputes the hourly delivery rollup on MySQL, which has no materialized view to REFRESH.
 *
 * One INSERT ... SELECT rebuilds every bucket in the window: the additive counts come from a
 * GROUP BY, and the per-bucket p50/p95 from a PARTITION BY window emulation that interpolates
 * exactly as PostgreSQL's percentile_cont does (a bucket with no measured durations LEFT JOINs to
 * NULL percentiles, just like the view). The owner morph pair is coalesced to the non-null
 * sentinel ('' plus the owner_key_type's zero value) the unique index needs. The bucket is
 * DATE_FORMAT truncation, which works on
 * the stored UTC-naive DATETIME directly and so never consults the session time zone. Both
 * created_at bounds bind the same 35-day-window start.
 *
 * The caller runs this inside a transaction after clearing the table, so readers keep seeing the
 * previous whole snapshot until it commits — the non-blocking behaviour REFRESH ... CONCURRENTLY
 * gives on PostgreSQL.
 *
 * @internal
 */
final class RollupRefresh
{
    public static function mysql(): string
    {
        // The null-owner sentinel matches the configured owner_key_type: a bigint 0 renders
        // unquoted, a uuid/ulid nil renders as a quoted char literal. It is a fixed package
        // constant, never user input, so splicing it into the SQL is safe.
        $sentinel = OwnerKeyType::fromConfig()->sentinelId();
        $sentinelSql = is_int($sentinel) ? (string) $sentinel : "'".$sentinel."'";

        $bucket = "CAST(DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS DATETIME)";
        $ownerType = "COALESCE(owner_type, '')";
        $ownerId = 'COALESCE(owner_id, '.$sentinelSql.')';

        return 'INSERT INTO webhook_delivery_hourly '
            .'(owner_type, owner_id, bucket, total, delivered, pending, failed, retried, p50, p95) '
            .'SELECT c.ot, c.oid, c.bkt, c.total, c.delivered, c.pending, c.failed, c.retried, p.p50, p.p95 '
            .'FROM ('
            .'SELECT '.$ownerType.' AS ot, '.$ownerId.' AS oid, '.$bucket.' AS bkt, '
            .'COUNT(*) AS total, '
            ."COUNT(CASE WHEN status = 'succeeded' THEN 1 END) AS delivered, "
            ."COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending, "
            ."COUNT(CASE WHEN status IN ('failed', 'exhausted') THEN 1 END) AS failed, "
            .'COUNT(CASE WHEN attempt > 1 THEN 1 END) AS retried '
            .'FROM webhook_deliveries WHERE created_at >= ? '
            .'GROUP BY ot, oid, bkt'
            .') c LEFT JOIN ('
            .'SELECT ot, oid, bkt, '
            .self::interpolate('50').' AS p50, '
            .self::interpolate('95').' AS p95 '
            .'FROM ('
            .'SELECT '.$ownerType.' AS ot, '.$ownerId.' AS oid, '.$bucket.' AS bkt, duration_ms AS d, '
            // rn ranks within the bucket (ordered window); cnt is the bucket total (an unordered
            // window — COUNT(*) OVER an ORDERED window is a running count, not the total).
            .'ROW_NUMBER() OVER wo AS rn, '
            .'FLOOR(0.50 * (COUNT(*) OVER wp - 1)) + 1 AS b50, '
            .'0.50 * (COUNT(*) OVER wp - 1) - FLOOR(0.50 * (COUNT(*) OVER wp - 1)) AS f50, '
            .'FLOOR(0.95 * (COUNT(*) OVER wp - 1)) + 1 AS b95, '
            .'0.95 * (COUNT(*) OVER wp - 1) - FLOOR(0.95 * (COUNT(*) OVER wp - 1)) AS f95 '
            .'FROM webhook_deliveries WHERE created_at >= ? AND duration_ms IS NOT NULL '
            .'WINDOW wp AS (PARTITION BY '.$ownerType.', '.$ownerId.', '.$bucket.'), '
            .'wo AS (PARTITION BY '.$ownerType.', '.$ownerId.', '.$bucket.' ORDER BY duration_ms)'
            .') ranked GROUP BY ot, oid, bkt'
            .') p ON p.ot = c.ot AND p.oid = c.oid AND p.bkt = c.bkt';
    }

    /**
     * The interpolation for one percentile within each partition: the value at the base rank plus
     * the fraction of the gap to the next rank (or the base value itself when it is the last row).
     */
    private static function interpolate(string $tier): string
    {
        $base = 'b'.$tier;
        $frac = 'f'.$tier;

        return 'MAX(CASE WHEN rn = '.$base.' THEN d END) + MAX('.$frac.') * ('
            .'COALESCE(MAX(CASE WHEN rn = '.$base.' + 1 THEN d END), MAX(CASE WHEN rn = '.$base.' THEN d END)) '
            .'- MAX(CASE WHEN rn = '.$base.' THEN d END))';
    }
}
