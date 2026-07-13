<?php

declare(strict_types=1);

namespace Webhooks\Client\Exceptions;

use RuntimeException;

/**
 * A stored call cannot hand back the bytes it received: its raw_body column is
 * absent, or holds something that is not the base64 the receiver wrote.
 *
 * This cannot happen to a row this package stored — the receiver writes raw_body for
 * every call it does not offload — so it means the row was written or edited by
 * something else. It throws rather than returning an empty body, because a caller
 * asking for the body means to re-verify or forward it, and a silently empty body
 * would fail that verification for the wrong reason.
 */
final class CorruptRawBody extends RuntimeException
{
    public static function for(string $id): self
    {
        return new self("The stored raw body of webhook call [{$id}] is missing or not valid base64.");
    }
}
