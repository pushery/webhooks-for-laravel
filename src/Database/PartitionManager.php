<?php

declare(strict_types=1);

namespace Webhooks\Database;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

/**
 * Creates and drops the monthly range partitions behind the webhook_deliveries
 * table. Shared by the create migration and the webhooks:partition-maintenance
 * command so the partition scheme has a single source of truth.
 *
 * Months are UTC months. The partition key is a timestamptz, so a bound is an
 * INSTANT, not a wall-clock string: anchoring the boundaries to a local calendar
 * would shift them by the local offset and — because that offset changes twice a
 * year — leave a one-hour gap or overlap between two adjacent partitions.
 *
 * The catch-all default partition is a safety net, not a resting place. A row that
 * lands in it blocks the creation of the partition that should have held it
 * ("updated partition constraint for default partition would be violated by some
 * row"), which would stop partition creation AND retention pruning dead. So the
 * manager drains it: creating a month whose rows sit in the default detaches the
 * default, creates the partition, moves those rows across and re-attaches — the
 * canonical PostgreSQL drain, in one transaction.
 */
final class PartitionManager
{
    private const string TABLE = 'webhook_deliveries';

    private const string DEFAULT_PARTITION = self::TABLE.'_default';

    private function db(): ConnectionInterface
    {
        return WebhookConnection::db();
    }

    public function partitionName(CarbonInterface $month): string
    {
        return self::TABLE.'_'.$this->monthStart($month)->format('Y_m');
    }

    /**
     * Ensure the partition for one month exists, draining any rows the default
     * partition is already holding for it. Returns the partition name.
     */
    public function ensureMonthlyPartition(CarbonInterface $month): string
    {
        $start = $this->monthStart($month);
        $end = $start->addMonth();
        $name = $this->partitionName($start);

        if ($this->tableExists($name)) {
            return $name;
        }

        if ($this->defaultPartitionCount($start, $end) > 0) {
            $this->drainDefaultPartitionInto($name, $start, $end);

            return $name;
        }

        $this->db()->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
            $name,
            self::TABLE,
            $this->quoteTimestamp($start),
            $this->quoteTimestamp($end),
        ));

        return $name;
    }

    public function ensureWindow(CarbonInterface $from, int $months): void
    {
        $cursor = $this->monthStart($from);

        for ($i = 0; $i < $months; $i++) {
            $this->ensureMonthlyPartition($cursor);
            $cursor = $cursor->addMonth();
        }
    }

    public function ensureDefaultPartition(): void
    {
        $this->db()->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s DEFAULT',
            self::DEFAULT_PARTITION,
            self::TABLE,
        ));
    }

    /**
     * Give every month currently stranded in the default partition its own monthly
     * partition, moving its rows across. This is the self-heal: a gap in the schedule
     * (a paused worker, a forgotten cron, a long deploy freeze) lets deliveries land in
     * the default, and from then on nothing else can be provisioned until they are
     * drained. Returns the months that were drained, oldest first.
     *
     * @return list<string> the drained partition names
     */
    public function drainDefaultPartition(): array
    {
        $drained = [];

        foreach ($this->defaultPartitionMonths() as $month) {
            $drained[] = $this->ensureMonthlyPartition($month);
        }

        return $drained;
    }

    /**
     * How many rows sit in the default partition — the drift signal an operator needs:
     * anything above zero means deliveries are landing outside the provisioned window.
     * Bounded to one month when a range is given.
     */
    public function defaultPartitionCount(?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        if (! $this->tableExists(self::DEFAULT_PARTITION)) {
            return 0;
        }

        $sql = 'SELECT count(*) AS total FROM '.self::DEFAULT_PARTITION;
        $bindings = [];

        if ($from instanceof CarbonInterface && $to instanceof CarbonInterface) {
            $sql .= ' WHERE created_at >= ? AND created_at < ?';
            $bindings = [Timestamp::sql($from), Timestamp::sql($to)];
        }

        $total = data_get($this->db()->selectOne($sql, $bindings), 'total');

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * The names of the monthly partitions (webhook_deliveries_YYYY_MM), excluding
     * the catch-all default partition.
     *
     * @return list<string>
     */
    public function monthlyPartitions(): array
    {
        $rows = $this->db()->select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = ?
            ORDER BY c.relname
            SQL, [self::TABLE]);

        $names = [];

        foreach ($rows as $row) {
            $name = data_get($row, 'name');

            if (is_string($name) && preg_match('/^'.self::TABLE.'_\d{4}_\d{2}$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Drop every monthly partition whose month begins before the given month.
     * The zero-padded YYYY_MM suffix compares chronologically as a plain string.
     *
     * @return list<string> the dropped partition names
     */
    public function dropPartitionsOlderThan(CarbonInterface $before): array
    {
        $cutoff = $this->monthStart($before)->format('Y_m');
        $prefixLength = strlen(self::TABLE) + 1;
        $dropped = [];

        foreach ($this->monthlyPartitions() as $name) {
            if (substr($name, $prefixLength) < $cutoff) {
                $this->db()->statement('DROP TABLE IF EXISTS '.$name);
                $dropped[] = $name;
            }
        }

        return $dropped;
    }

    /**
     * The distinct UTC months for which the default partition is currently holding
     * rows, oldest first.
     *
     * @return list<CarbonImmutable>
     */
    private function defaultPartitionMonths(): array
    {
        if (! $this->tableExists(self::DEFAULT_PARTITION)) {
            return [];
        }

        $rows = $this->db()->select(
            'SELECT DISTINCT date_trunc(\'month\', created_at AT TIME ZONE \'UTC\') AS month '
            .'FROM '.self::DEFAULT_PARTITION.' ORDER BY month'
        );

        $months = [];

        foreach ($rows as $row) {
            $month = data_get($row, 'month');

            if (is_string($month)) {
                $months[] = CarbonImmutable::parse($month, 'UTC');
            }
        }

        return $months;
    }

    /**
     * The canonical PostgreSQL default-partition drain, in one transaction: detach the
     * default (a partitioned table refuses to create a partition whose range the
     * default already holds rows for), create the monthly partition, move that month's
     * rows into it through the parent table, and re-attach the default.
     *
     * The generated columns are excluded from the column list — PostgreSQL refuses an
     * explicit value for one — so the moved rows recompute theirs on insert.
     */
    private function drainDefaultPartitionInto(string $name, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $columns = implode(', ', $this->insertableColumns());
        $from = Timestamp::sql($start);
        $to = Timestamp::sql($end);

        $this->db()->transaction(function () use ($name, $start, $end, $columns, $from, $to): void {
            $this->db()->statement(sprintf('ALTER TABLE %s DETACH PARTITION %s', self::TABLE, self::DEFAULT_PARTITION));

            $this->db()->statement(sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
                $name,
                self::TABLE,
                $this->quoteTimestamp($start),
                $this->quoteTimestamp($end),
            ));

            $this->db()->statement(
                sprintf('INSERT INTO %s (%s) SELECT %s FROM %s WHERE created_at >= ? AND created_at < ?', self::TABLE, $columns, $columns, self::DEFAULT_PARTITION),
                [$from, $to],
            );

            $this->db()->statement(
                sprintf('DELETE FROM %s WHERE created_at >= ? AND created_at < ?', self::DEFAULT_PARTITION),
                [$from, $to],
            );

            $this->db()->statement(sprintf('ALTER TABLE %s ATTACH PARTITION %s DEFAULT', self::TABLE, self::DEFAULT_PARTITION));
        });
    }

    /**
     * The table's real (non-generated) columns, in declaration order. A generated
     * column may not be written explicitly, so it is left out of the drain's INSERT.
     *
     * @return list<string>
     */
    private function insertableColumns(): array
    {
        $rows = $this->db()->select(
            'SELECT column_name FROM information_schema.columns '
            .'WHERE table_schema = current_schema() AND table_name = ? AND is_generated = \'NEVER\' '
            .'ORDER BY ordinal_position',
            [self::TABLE],
        );

        $columns = [];

        foreach ($rows as $row) {
            $column = data_get($row, 'column_name');

            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function tableExists(string $name): bool
    {
        return data_get($this->db()->selectOne('SELECT to_regclass(?) AS oid', [$name]), 'oid') !== null;
    }

    /**
     * The UTC month a moment falls in, as an instant.
     */
    private function monthStart(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment)->setTimezone('UTC')->startOfMonth();
    }

    private function quoteTimestamp(CarbonInterface $moment): string
    {
        return "'".Timestamp::sql($moment)."'";
    }
}
