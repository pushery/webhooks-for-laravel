<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webhooks\Dashboard\Metrics\WebhookMetrics;

/**
 * Refreshes the hourly delivery-metrics materialized view. Runs CONCURRENTLY so the
 * dashboard keeps reading the previous snapshot while the refresh builds the next
 * one — which is why the view carries a unique index. Scheduled non-overlapping by
 * the dashboard service provider at the configured cadence.
 *
 * @internal
 */
final class RefreshMetricsCommand extends Command
{
    protected $signature = 'webhooks:refresh-metrics';

    protected $description = 'Refresh the hourly webhook delivery-metrics materialized view.';

    public function handle(): int
    {
        $view = WebhookMetrics::HOURLY_VIEW;

        DB::statement(self::refreshStatement($view, DB::transactionLevel()));

        $this->info("Refreshed the {$view} materialized view.");

        return self::SUCCESS;
    }

    /**
     * The refresh statement for the given transaction depth. CONCURRENTLY keeps the
     * dashboard readable while the next snapshot builds — the production path, since
     * the scheduler runs the command outside any transaction — but PostgreSQL forbids
     * it inside a transaction block. So an already-open transaction (a test harness
     * wrapping each case in a rolled-back transaction) takes the equivalent blocking
     * refresh instead of failing.
     */
    public static function refreshStatement(string $view, int $transactionLevel): string
    {
        return $transactionLevel > 0
            ? "REFRESH MATERIALIZED VIEW {$view}"
            : "REFRESH MATERIALIZED VIEW CONCURRENTLY {$view}";
    }
}
