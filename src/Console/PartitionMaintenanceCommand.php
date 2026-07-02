<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Webhooks\Database\PartitionManager;
use Webhooks\Support\WebhookConfig;

/**
 * Keeps the webhook_deliveries partition set rolling: provisions the upcoming
 * months ahead of time so inserts always land in a real partition, and drops
 * partitions older than the configured retention window (a cheap metadata
 * operation compared with a bulk DELETE). Scheduled daily by the service provider.
 */
final class PartitionMaintenanceCommand extends Command
{
    protected $signature = 'webhooks:partition-maintenance';

    protected $description = 'Provision upcoming webhook delivery-log partitions and drop those past the retention window.';

    public function handle(PartitionManager $partitions, WebhookConfig $config): int
    {
        $monthsAhead = $config->partitionMonthsAhead();

        // Provision the current month through $monthsAhead months from now.
        $partitions->ensureWindow(CarbonImmutable::now()->startOfMonth(), $monthsAhead + 1);

        $cutoff = CarbonImmutable::now()->startOfMonth()->subMonths($config->retentionMonths());
        $dropped = $partitions->dropPartitionsOlderThan($cutoff);

        $this->info(sprintf(
            'Provisioned partitions %d month(s) ahead; dropped %d partition(s) before %s.',
            $monthsAhead,
            count($dropped),
            $cutoff->format('Y-m'),
        ));

        return self::SUCCESS;
    }
}
