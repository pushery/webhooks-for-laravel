<?php

declare(strict_types=1);

namespace Webhooks\Core\Payload;

use Illuminate\Support\Facades\Storage;
use Webhooks\Core\Payload\Exceptions\OffloadFailed;

/**
 * Moves an over-sized webhook body off the database and onto a Storage disk,
 * leaving the row with only a pointer (disk + path) and the body's SHA-256. The
 * path is content-addressed by that hash, so the same bytes are stored once and a
 * fanned-out delivery to many endpoints shares a single object. Reading the body
 * back is a plain disk fetch keyed by the stored pointer.
 *
 * The offloaded object is the ONLY copy of the body, so a failed write is fatal, not
 * a warning: Laravel's Filesystem returns FALSE from put() instead of throwing unless
 * the disk sets 'throw' => true, and sailing on past that would write a row pointing
 * at an object that does not exist — a webhook destroyed in silence. Every write is
 * therefore verified, and a failure throws so the caller's own retry can re-deliver.
 *
 * @internal
 */
final class PayloadStore
{
    /**
     * Write the body to the disk and return the pointer to persist. The object key
     * is derived from the content hash and sharded on its first two hex characters
     * so no single directory accumulates every object.
     *
     * @return array{path: string, sha256: string}
     *
     * @throws OffloadFailed when the disk did not accept the body.
     */
    public function offload(string $body, string $disk): array
    {
        $sha256 = hash('sha256', $body);
        $path = self::pathFor($sha256);

        if (Storage::disk($disk)->put($path, $body) !== true) {
            throw OffloadFailed::write($disk, $path);
        }

        return ['path' => $path, 'sha256' => $sha256];
    }

    /**
     * Read a previously offloaded body back from the disk, throwing when the object
     * is missing so a silently-truncated payload can never masquerade as empty.
     *
     * @throws OffloadFailed when the object is gone.
     */
    public function rehydrate(string $disk, string $path): string
    {
        return Storage::disk($disk)->get($path) ?? throw OffloadFailed::missing($disk, $path);
    }

    /**
     * The content-addressed object key for a body hash: webhooks/{ab}/{sha256}.
     */
    public static function pathFor(string $sha256): string
    {
        return 'webhooks/'.substr($sha256, 0, 2).'/'.$sha256;
    }
}
