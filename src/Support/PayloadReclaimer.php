<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
 * Only a key in the exact shape the package writes is a candidate. The prefix is a plain
 * word a host can use on the same disk, so a file of any other shape under it is somebody
 * else's: it is counted as foreign and never deleted.
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

    /** That key exactly: the directory is the first two hex digits of the hash that follows it. */
    private const string OBJECT_KEY = '#\Awebhooks/([0-9a-f]{2})/\1[0-9a-f]{62}\z#';

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
     * @return array{scanned: int, orphaned: int, deleted: int, bytes: int, foreign: int}
     */
    public function reclaim(string $disk, bool $dryRun): array
    {
        $filesystem = Storage::disk($disk);
        $referenced = $this->referencedPaths($disk);

        $foreign = 0;
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
            if (preg_match(self::OBJECT_KEY, $path) !== 1) {
                $foreign++;

                continue;
            }

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

        return ['scanned' => $scanned, 'orphaned' => $orphaned, 'deleted' => $deleted, 'bytes' => $bytes, 'foreign' => $foreign];
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

        // Two shapes in this method and the loop above cannot change the outcome:
        //
        // the value stored in `$referenced[$path]` — the read is isset(), and isset() is true for
        // any stored value but null, which is not stored here;
        //
        // the `is_string()` checks on the paths — a row that offloaded nothing yields null, and
        // `$referenced[null]` lands under the key '' rather than matching any real path.
        //
        // They stay, and the second one earns its keep beyond the type: from PHP 8.5 a null array
        // offset is deprecated (8.4 says nothing), so unwrapping it trades a silent no-op for a
        // deprecation the day a row carries no path.
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
        // The tables, not the models: this is the one reader in the package that must not go through
        // `webhooks.models`. What is built here is the set of paths something still
        // references, and every object outside it is deleted. A host's subclass may carry a global
        // scope — a tenant, a soft delete — and read through it, the rows the scope hides would drop
        // out of the set and their payloads would be deleted while those rows still point at them.
        // The package class is no better: the host configured its own because it wants its class,
        // and naming ours here is the bypass the model guard exists for. The rows are the answer,
        // whichever class a host reads them with.
        if ($schema->hasTable('webhook_deliveries')) {
            WebhookConnection::db()->table('webhook_deliveries')
                ->where('payload_disk', $disk)
                ->select(['id', 'payload_path'])
                ->lazyById(self::CHUNK)
                ->each(function (object $row) use (&$referenced): void {
                    if (is_string($row->payload_path)) {
                        $referenced[$row->payload_path] = true;
                    }
                });
        }

        if ($schema->hasTable('webhook_calls')) {
            WebhookConnection::db()->table('webhook_calls')
                ->where('payload_disk', $disk)
                ->select(['id', 'payload_path'])
                ->lazyById(self::CHUNK)
                ->each(function (object $row) use (&$referenced): void {
                    if (is_string($row->payload_path)) {
                        $referenced[$row->payload_path] = true;
                    }
                });
        }

        return $referenced;
    }
}
