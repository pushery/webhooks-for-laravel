<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Metrics;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Detects the optional PostgreSQL `tdigest` extension that powers the Tier-2
 * percentile driver. The extension is never required: the default `live` driver
 * runs on stock PostgreSQL, and the hourly rollup builds without a digest column
 * when the extension is absent. Only a host that opts into the `tdigest` driver on
 * a high-volume tenant installs it (CREATE EXTENSION tdigest) and rebuilds the view.
 */
final class TdigestExtension
{
    /**
     * The extension name as registered in pg_extension.
     */
    public const string NAME = 'tdigest';

    /**
     * Whether the tdigest extension is installed on the given connection (the default
     * connection when null). A non-PostgreSQL connection simply reports false.
     */
    public static function isInstalled(?string $connection = null): bool
    {
        $resolved = DB::connection($connection);

        if ($resolved->getDriverName() !== 'pgsql') {
            return false;
        }

        return $resolved->scalar('SELECT 1 FROM pg_extension WHERE extname = ?', [self::NAME]) !== null;
    }

    /**
     * Assert the extension is installed AND the rollup carries its digest column, throwing
     * one clear, actionable error naming the exact remediation when either is missing —
     * never a cryptic missing-function or missing-column failure deep inside the percentile
     * SQL. The two are independent: the column is added only when the extension is present as
     * the migrations run, so installing the extension AFTERWARDS leaves the column absent.
     *
     * @throws RuntimeException when the tdigest driver is selected but the extension is absent,
     *                          or present but the rollup has no latency_digest column yet
     */
    public static function ensureInstalled(?string $connection = null): void
    {
        if (! self::isInstalled($connection)) {
            throw new RuntimeException(
                'The "tdigest" percentile driver requires the PostgreSQL tdigest extension, which is not '
                .'installed on this database. Install it once with `CREATE EXTENSION tdigest;` and re-run the '
                .'dashboard migrations so the hourly rollup gains its latency_digest column, or set '
                .'webhooks.dashboard.percentiles.driver back to "live" (the default, which needs no extension).'
            );
        }

        if (! self::hasDigestColumn($connection)) {
            throw new RuntimeException(
                'The "tdigest" percentile driver is enabled and the extension is installed, but the '
                .'webhook_delivery_hourly rollup has no latency_digest column — that column is added only '
                .'when the extension is present as the dashboard migrations run, so installing the extension '
                .'afterwards is not enough. Rebuild the rollup (roll back and re-run the dashboard migrations) '
                .'so it gains the column, or set webhooks.dashboard.percentiles.driver back to "live".'
            );
        }
    }

    /**
     * Whether the hourly rollup carries the latency_digest column (added by the migrations
     * only when the extension is present). Works for the materialized view via pg_class, on
     * which information_schema.columns does not report.
     */
    private static function hasDigestColumn(?string $connection = null): bool
    {
        return DB::connection($connection)->scalar(
            'SELECT 1 FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid '
            .'WHERE c.relname = ? AND a.attname = ? AND a.attnum > 0 AND NOT a.attisdropped',
            ['webhook_delivery_hourly', 'latency_digest']
        ) !== null;
    }
}
