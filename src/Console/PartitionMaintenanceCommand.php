<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\PartitionManager;
use Webhooks\Support\Settings;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

/**
 * Keeps the webhook_deliveries partition set rolling: drains anything the catch-all
 * default partition has caught, provisions the upcoming months ahead of time so
 * inserts always land in a real partition, and drops partitions older than the
 * configured retention window (a cheap metadata operation compared with a bulk
 * DELETE). Scheduled daily by the service provider.
 *
 * The drain runs FIRST, and it is what makes this command self-healing. If the
 * schedule ever stops for longer than the provisioned runway — a paused worker, a
 * cron never installed on a new box, a long deploy freeze — deliveries land in the
 * default partition, and PostgreSQL then refuses to create the very partition that
 * should hold them. Without the drain, one such row would stop partition creation and
 * retention pruning for good.
 *
 * @internal
 */
final class PartitionMaintenanceCommand extends Command
{
    protected $signature = 'webhooks:partition-maintenance';

    protected $description = 'Provision upcoming webhook delivery-log partitions and drop those past the retention window.';

    /**
     * The chunk size for the MySQL retention delete. Small enough to keep each statement's
     * lock footprint and undo log bounded on a large table, large enough to make progress.
     */
    private const int PRUNE_CHUNK = 1000;

    public function handle(PartitionManager $partitions, Settings $config): int
    {
        if (WebhookConnection::dialect() === Dialect::MySql) {
            return $this->pruneByChunkedDelete($config);
        }

        $monthsAhead = $config->partitionMonthsAhead();

        // Rescue any delivery that landed in the catch-all default partition before
        // provisioning: while such a row sits there, the partition covering its month
        // cannot be created at all. The count is read first, because the drain is what
        // makes it zero again — and the operator needs to see the drift, not only the
        // repair.
        $stranded = $partitions->defaultPartitionCount();
        $drained = $partitions->drainDefaultPartition();

        if ($stranded > 0) {
            $this->warn(sprintf(
                'Drained %d delivery row(s) out of the default partition into %s — deliveries had been landing outside the provisioned window, which blocks partition creation and retention pruning until it is healed. Check that the scheduler runs this command daily.',
                $stranded,
                implode(', ', $drained),
            ));
        }

        // Provision the current UTC month through $monthsAhead months from now. The
        // partition key is a timestamptz, so the months are UTC months.
        $partitions->ensureWindow(CarbonImmutable::now('UTC')->startOfMonth(), $monthsAhead + 1);

        $cutoff = CarbonImmutable::now('UTC')->startOfMonth()->subMonths($config->retentionMonths());
        $dropped = $partitions->dropPartitionsOlderThan($cutoff);

        $this->info(sprintf(
            'Provisioned partitions %d month(s) ahead; drained %d month(s) out of the default partition; dropped %d partition(s) before %s.',
            $monthsAhead,
            count($drained),
            count($dropped),
            $cutoff->format('Y-m'),
        ));

        return self::SUCCESS;
    }

    /**
     * MySQL retention: the flat delivery log has no partition to drop, so rows past the window
     * are removed with an indexed, chunked DELETE (the created_at index makes each pass cheap).
     * The cutoff is a fixed instant months in the past, so no new row ever falls below it and
     * the loop always terminates. Same window as the PostgreSQL path — only the mechanism differs.
     */
    private function pruneByChunkedDelete(Settings $config): int
    {
        $cutoff = CarbonImmutable::now('UTC')->startOfMonth()->subMonths($config->retentionMonths());
        $cutoffLiteral = Timestamp::mysql($cutoff);

        $deleted = 0;

        do {
            $affected = WebhookConnection::db()->table('webhook_deliveries')
                ->where('created_at', '<', $cutoffLiteral)
                ->limit(self::PRUNE_CHUNK)
                ->delete();

            $deleted += $affected;
        } while ($affected === self::PRUNE_CHUNK);

        $this->info(sprintf(
            'Pruned %d delivery row(s) older than %s in chunks of %d (MySQL has no partition to drop).',
            $deleted,
            $cutoff->format('Y-m'),
            self::PRUNE_CHUNK,
        ));

        return self::SUCCESS;
    }
}
