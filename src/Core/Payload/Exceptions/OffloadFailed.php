<?php

declare(strict_types=1);

namespace Webhooks\Core\Payload\Exceptions;

use RuntimeException;

/**
 * The webhook body could not be written to (or read back from) the offload disk.
 *
 * It is thrown rather than swallowed on purpose: the offloaded object is the ONLY
 * copy of the body, so a row written with a pointer to an object that does not exist
 * is a permanently lost webhook. Failing here aborts the store, which lets the
 * caller's own at-least-once machinery — the producer's retry inbound, the queue's
 * retry outbound — deliver the body again.
 */
final class OffloadFailed extends RuntimeException
{
    public static function write(string $disk, string $path): self
    {
        return new self(
            "Failed to write the webhook body to [{$path}] on the offload disk [{$disk}]. ".
            'Nothing was stored. Configure the disk with \'throw\' => true to surface the underlying driver error.'
        );
    }

    public static function missing(string $disk, string $path): self
    {
        return new self("The offloaded webhook body [{$path}] is missing from disk [{$disk}].");
    }
}
