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
     * Assert the extension is installed, throwing one clear, actionable error naming
     * the exact remediation when it is not — never a cryptic missing-function or
     * missing-column failure deep inside the percentile SQL.
     *
     * @throws RuntimeException when the tdigest driver is selected but the extension is absent
     */
    public static function ensureInstalled(?string $connection = null): void
    {
        if (self::isInstalled($connection)) {
            return;
        }

        throw new RuntimeException(
            'The "tdigest" percentile driver requires the PostgreSQL tdigest extension, which is not '
            .'installed on this database. Install it once with `CREATE EXTENSION tdigest;` and re-run the '
            .'dashboard migrations so the hourly rollup gains its latency_digest column, or set '
            .'webhooks.dashboard.percentiles.driver back to "live" (the default, which needs no extension).'
        );
    }
}
