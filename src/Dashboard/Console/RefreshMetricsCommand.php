<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Pushery\Webhooks\Dashboard\Metrics\WebhookMetrics;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Database\Dialect\Sql\RollupRefresh;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;

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
            $this->db()->statement(self::refreshStatement($view, $this->holdsSnapshot($view)));
        }

        $this->info("Refreshed the {$view} delivery-metrics rollup.");

        return self::SUCCESS;
    }

    /**
     * MySQL has no materialized view to REFRESH, so the rollup table is rebuilt in place: clear it
     * and re-aggregate the window in one transaction, so a reader never sees a half-built or empty
     * rollup — the whole previous snapshot stays visible (InnoDB consistent reads) until commit.
     * The transaction runs at READ COMMITTED where MySQL allows it, so the delivery log it reads
     * stays writable meanwhile ({@see self::mysqlIsolationStatement()}).
     */
    private function refreshMySql(string $view): void
    {
        $since = Timestamp::mysql(CarbonImmutable::now('UTC')->subDays(35));

        // The binary-log query runs before the depth is read. A connection that opens its transaction
        // on the first statement it executes, as LazilyRefreshDatabase does, has opened it by then.
        $logsStatements = $this->logsStatements();
        $isolation = self::mysqlIsolationStatement($this->db()->transactionLevel(), $logsStatements);

        if ($isolation !== null) {
            $this->db()->statement($isolation);
        }

        $this->db()->transaction(function () use ($view, $since): void {
            $this->db()->table($view)->delete();
            $this->db()->insert(RollupRefresh::mysql(), [$since, $since]);
        });
    }

    /**
     * The statement that runs the next transaction at READ COMMITTED, or null where MySQL refuses it.
     *
     * Under REPEATABLE READ, MySQL's default, INSERT ... SELECT takes a shared next-key lock on
     * every row it reads from the delivery log, so updating a delivery inside the window or
     * inserting a new one waits until the refresh commits. READ COMMITTED reads the log without
     * locks, and readers of the rollup still see the previous snapshot until the commit. Two
     * places refuse it: a transaction a caller already opened, whose characteristics can no longer
     * change, and statement-based binary logging, which cannot log an INSERT ... SELECT read at
     * READ COMMITTED. There the refresh keeps the default and its locks.
     */
    public static function mysqlIsolationStatement(int $transactionLevel, bool $logsStatements): ?string
    {
        return $transactionLevel === 0 && ! $logsStatements
            ? 'set transaction isolation level read committed'
            : null;
    }

    /**
     * Whether MySQL writes its binary log statement by statement.
     */
    private function logsStatements(): bool
    {
        $logging = (array) $this->db()->selectOne('SELECT @@log_bin AS log_bin, @@binlog_format AS format');
        $format = $logging['format'] ?? null;

        return in_array($logging['log_bin'] ?? null, [1, '1'], true)
            && is_string($format)
            && strtoupper($format) === 'STATEMENT';
    }

    /**
     * Whether the view holds a snapshot. One created or refreshed WITH NO DATA holds none, and so
     * does one a schema-only restore left behind, since such a dump carries no REFRESH.
     *
     * Counted rather than read as a boolean, so a connection that returns every value as a string
     * gives the same answer.
     */
    private function holdsSnapshot(string $view): bool
    {
        $populated = data_get($this->db()->selectOne(
            'select count(*) as populated from pg_class where oid = to_regclass(?) and relispopulated',
            [$view],
        ), 'populated');

        return is_numeric($populated) && (int) $populated > 0;
    }

    /**
     * The refresh statement for the view's state. CONCURRENTLY keeps the dashboard readable while
     * the next snapshot builds, and PostgreSQL runs it inside a transaction as well as outside one.
     * It refuses it for a view that holds no snapshot yet (0A000), and every scheduled run would
     * then fail the same way, so that one state takes the blocking refresh, which fills the view.
     * The next run is concurrent again.
     */
    public static function refreshStatement(string $view, bool $holdsSnapshot): string
    {
        if (! $holdsSnapshot) {
            return "REFRESH MATERIALIZED VIEW {$view}";
        }

        return "REFRESH MATERIALIZED VIEW CONCURRENTLY {$view}";
    }
}
