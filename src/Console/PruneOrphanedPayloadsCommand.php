<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Number;
use Webhooks\Support\PayloadReclaimer;

/**
 * Deletes offloaded payload objects on the configured Storage disk(s) that no log row still
 * points at — the app-side alternative to a bucket lifecycle policy for reclaiming space when
 * `large_payload` offload is on.
 *
 * Retention (row pruning / partition drops) removes rows, never the disk objects, and offloaded
 * bodies are content-addressed, so an object cannot be deleted per-row without stranding another
 * row that shares it. For object storage a lifecycle policy that expires by last-modified age is
 * the cheaper, standard reclamation (no full-bucket LIST) — reach for this command instead when
 * you offload to a LOCAL disk, or when compliance requires app-controlled deletion. It is NOT
 * scheduled by default: run or schedule it yourself, off-peak (it assumes offload writes are
 * quiesced for the sweep).
 *
 * @internal
 */
final class PruneOrphanedPayloadsCommand extends Command
{
    protected $signature = 'webhooks:prune-orphaned-payloads
        {--disk= : The Storage disk to sweep (defaults to the Server offload disk)}
        {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete offloaded payload objects no delivery-log or call-log row still references.';

    public function handle(PayloadReclaimer $reclaimer): int
    {
        $disks = $this->disks();

        if ($disks === []) {
            $this->warn('Offload is not enabled on any layer. Pass --disk to sweep a specific disk (e.g. left-over objects after disabling offload).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalOrphaned = 0;
        $totalDeleted = 0;
        $totalBytes = 0;

        foreach ($disks as $disk) {
            $result = $reclaimer->reclaim($disk, $dryRun);
            $totalOrphaned += $result['orphaned'];
            $totalDeleted += $result['deleted'];
            $totalBytes += $result['bytes'];

            $this->line(sprintf(
                '  %s: %d scanned, %d orphaned%s.',
                $disk,
                $result['scanned'],
                $result['orphaned'],
                $dryRun ? '' : sprintf(', %d deleted', $result['deleted']),
            ));
        }

        $size = Number::fileSize($totalBytes);

        $this->info($dryRun
            ? sprintf('Dry run: %d orphaned object(s) holding %s would be deleted.', $totalOrphaned, $size)
            : sprintf('Reclaimed %d orphaned object(s), freeing %s.', $totalDeleted, $size));

        return self::SUCCESS;
    }

    /**
     * The disk(s) to sweep: an explicit --disk wins; otherwise the Server layer's offload disk
     * when Server offload is enabled. A Client source can offload to its own disk and a disk
     * can hold left-over objects after offload was turned off — pass --disk for those.
     *
     * @return array<int, string>
     */
    private function disks(): array
    {
        $override = $this->option('disk');

        if (is_string($override) && $override !== '') {
            return [$override];
        }

        if (Config::boolean('webhooks.server.large_payload.enabled', false)) {
            return [Config::string('webhooks.server.large_payload.disk', 's3')];
        }

        return [];
    }
}
