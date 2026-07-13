<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Webhooks\Database\PartitionManager;
use Webhooks\Support\Settings;

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

    public function handle(PartitionManager $partitions, Settings $config): int
    {
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
}
