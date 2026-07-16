<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Webhooks\Client\Models\WebhookCall;
use Webhooks\Models\WebhookDelivery;

/**
 * Deletes offloaded payload objects on a Storage disk that no log row still points at.
 *
 * Offloaded bodies are content-addressed (the object key is the body's SHA-256), so ONE
 * object is shared by every row that carried the same bytes — a fanned-out delivery to many
 * endpoints, a producer that re-sent an identical payload. An object is therefore an orphan
 * only when NO row in ANY offload log still references it; deleting it while a single row
 * points at it would strand that row's body. The two tables that hold offload pointers are
 * webhook_deliveries (the delivery log) and webhook_calls (the inbound call log); the
 * standalone server-delivery log does not offload, so it is not consulted.
 *
 * The full set of still-referenced object keys for the disk is read once, then the disk is
 * walked and every key not in that set is deleted. It assumes offload writes are quiesced for
 * the run (schedule it off-peak): a body offloaded AFTER the reference set was read but BEFORE
 * the delete would otherwise be stranded — the same eventual-consistency caveat a disk
 * lifecycle policy carries.
 *
 * @internal
 */
final class PayloadReclaimer
{
    /** Every offloaded object key is `webhooks/{ab}/{sha256}` (see PayloadStore::pathFor). */
    private const string PREFIX = 'webhooks';

    /**
     * Sweep one disk. Returns the tally; when $dryRun is true nothing is deleted but the
     * orphans (and the bytes they hold) are still counted, so an operator can preview the run.
     *
     * @return array{scanned: int, orphaned: int, deleted: int, bytes: int}
     */
    public function reclaim(string $disk, bool $dryRun): array
    {
        $filesystem = Storage::disk($disk);
        $referenced = $this->referencedPaths($disk);

        $scanned = 0;
        $orphaned = 0;
        $deleted = 0;
        $bytes = 0;

        // allFiles() is typed as a bare array; keep only the string keys it actually yields so
        // the path stays a string for the disk operations below.
        foreach (array_filter($filesystem->allFiles(self::PREFIX), is_string(...)) as $path) {
            $scanned++;

            if (isset($referenced[$path])) {
                continue;
            }

            $orphaned++;
            $bytes += $filesystem->size($path);

            if (! $dryRun) {
                $filesystem->delete($path);
                $deleted++;
            }
        }

        return ['scanned' => $scanned, 'orphaned' => $orphaned, 'deleted' => $deleted, 'bytes' => $bytes];
    }

    /**
     * Every object key still referenced on $disk, as a set keyed by key. Only rows on THIS
     * disk count — a row on another disk points at a different physical object with the same
     * content hash. A table that is not present (its layer never migrated) is skipped rather
     * than errored, so the sweep works whether one or both layers persist.
     *
     * @return array<string, true>
     */
    private function referencedPaths(string $disk): array
    {
        $schema = Schema::connection(WebhookConnection::name());
        $referenced = [];

        if ($schema->hasTable('webhook_deliveries')) {
            $paths = WebhookDelivery::query()->where('payload_disk', $disk)->pluck('payload_path')->all();

            foreach (array_filter($paths, is_string(...)) as $path) {
                $referenced[$path] = true;
            }
        }

        if ($schema->hasTable('webhook_calls')) {
            $paths = WebhookCall::query()->where('payload_disk', $disk)->pluck('payload_path')->all();

            foreach (array_filter($paths, is_string(...)) as $path) {
                $referenced[$path] = true;
            }
        }

        return $referenced;
    }
}
