<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Webhooks\Dashboard\Metrics\WebhookMetrics;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\Dialect\Sql\RollupRefresh;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

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

    private function db(): ConnectionInterface
    {
        return WebhookConnection::db();
    }

    public function handle(): int
    {
        $view = WebhookMetrics::HOURLY_VIEW;

        if (WebhookConnection::dialect() === Dialect::MySql) {
            $this->refreshMySql($view);
        } else {
            $this->db()->statement(self::refreshStatement($view, $this->db()->transactionLevel()));
        }

        $this->info("Refreshed the {$view} delivery-metrics rollup.");

        return self::SUCCESS;
    }

    /**
     * MySQL has no materialized view to REFRESH, so the rollup table is rebuilt in place: clear it
     * and re-aggregate the window in one transaction, so a reader never sees a half-built or empty
     * rollup — the whole previous snapshot stays visible (InnoDB consistent reads) until commit.
     */
    private function refreshMySql(string $view): void
    {
        $since = Timestamp::mysql(CarbonImmutable::now('UTC')->subDays(35));

        $this->db()->transaction(function () use ($view, $since): void {
            $this->db()->table($view)->delete();
            $this->db()->insert(RollupRefresh::mysql(), [$since, $since]);
        });
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
