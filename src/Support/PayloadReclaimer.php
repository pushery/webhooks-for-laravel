<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Models\WebhookDelivery;

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
     * Rows per round trip while building the reference set.
     *
     * Big enough that a large log is not a million queries, small enough that one chunk is a
     * rounding error next to the set it feeds. The set itself is unavoidable; the transient copy
     * pluck()->all() made beside it was not.
     */
    private const int CHUNK = 1000;

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
        //
        // The filter removes nothing at run time, because the driver only ever yields strings. It
        // stays because the guarantee it makes is a type guarantee, and the operations below are
        // typed for a string.
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

        // Three shapes in this method and the loop above cannot change the outcome, and all three
        // were measured together with the prune suite green:
        //
        // `$referenced[$path] = true` moved to `false` — the read is isset(), and isset() is true
        // for a stored false. Only null would make it false, and null is not stored here.
        //
        // both `array_filter(..., is_string(...))` calls unwrapped — pluck() yields null for a row
        // that offloaded nothing, and `$referenced[null]` lands under the key '' rather than
        // matching any real path.
        //
        // The controls are the suite's own arms, not something added for this note: it
        // deletes an object nothing references, keeps one a delivery row references, keeps one a
        // call row references, and keeps scanning past a referenced object. A reference map that
        // did not work would fail all four.
        //
        // They stay, and the second one earns its keep beyond the type: on PHP 8.4 a null array
        // offset is deprecated, so unwrapping it trades a silent no-op for a notice the day a row
        // carries no path.
        // Streamed rather than pluck()->all(), and the reason is the second copy. The set below has
        // to hold every referenced key — that is what it is for, and no amount of chunking changes
        // it. What pluck()->all() added on top was a full second array of the same paths, alive at
        // the same time, for the duration of the copy. On a log with millions of offloaded rows
        // that doubled the peak for no gain. lazyById() holds one chunk at a time instead.
        //
        // It cannot make the scan cheap: `payload_disk` carries no index on either engine, and
        // adding one would cost the delivery log's hot insert path for a sweep that is manual,
        // unscheduled and meant to run off-peak. That trade is named in the command's docblock
        // instead of being made quietly here.
        if ($schema->hasTable('webhook_deliveries')) {
            WebhookDelivery::query()
                ->where('payload_disk', $disk)
                ->select(['id', 'payload_path'])
                ->lazyById(self::CHUNK)
                ->each(function (WebhookDelivery $row) use (&$referenced): void {
                    if (is_string($row->payload_path)) {
                        $referenced[$row->payload_path] = true;
                    }
                });
        }

        if ($schema->hasTable('webhook_calls')) {
            WebhookCall::query()
                ->where('payload_disk', $disk)
                ->select(['id', 'payload_path'])
                ->lazyById(self::CHUNK)
                ->each(function (WebhookCall $row) use (&$referenced): void {
                    if (is_string($row->payload_path)) {
                        $referenced[$row->payload_path] = true;
                    }
                });
        }

        return $referenced;
    }
}
