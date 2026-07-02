<?php

declare(strict_types=1);

namespace Webhooks\Database;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates and drops the monthly range partitions behind the webhook_deliveries
 * table. Shared by the create migration and the webhooks:partition-maintenance
 * command so the partition scheme has a single source of truth.
 */
final class PartitionManager
{
    private const string TABLE = 'webhook_deliveries';

    public function partitionName(CarbonInterface $month): string
    {
        return self::TABLE.'_'.CarbonImmutable::parse($month)->format('Y_m');
    }

    public function ensureMonthlyPartition(CarbonInterface $month): string
    {
        $start = CarbonImmutable::parse($month)->startOfMonth();
        $end = $start->addMonth();
        $name = $this->partitionName($start);

        DB::statement(sprintf(
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
        $cursor = CarbonImmutable::parse($from)->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $this->ensureMonthlyPartition($cursor);
            $cursor = $cursor->addMonth();
        }
    }

    public function ensureDefaultPartition(): void
    {
        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s_default PARTITION OF %s DEFAULT',
            self::TABLE,
            self::TABLE,
        ));
    }

    /**
     * The names of the monthly partitions (webhook_deliveries_YYYY_MM), excluding
     * the catch-all default partition.
     *
     * @return list<string>
     */
    public function monthlyPartitions(): array
    {
        $rows = DB::select(<<<'SQL'
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
        $cutoff = CarbonImmutable::parse($before)->format('Y_m');
        $prefixLength = strlen(self::TABLE) + 1;
        $dropped = [];

        foreach ($this->monthlyPartitions() as $name) {
            if (substr($name, $prefixLength) < $cutoff) {
                DB::statement('DROP TABLE IF EXISTS '.$name);
                $dropped[] = $name;
            }
        }

        return $dropped;
    }

    private function quoteTimestamp(CarbonInterface $moment): string
    {
        return "'".CarbonImmutable::parse($moment)->format('Y-m-d H:i:s')."'";
    }
}
