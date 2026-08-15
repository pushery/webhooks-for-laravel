<?php

declare(strict_types=1);

namespace Webhooks\Client\Exceptions;

use RuntimeException;
use Throwable;
use Webhooks\Client\Events\UnreadableWebhookPayload;

/**
 * A listener on {@see UnreadableWebhookPayload} threw, and the delivery it was announcing
 * was already stored and queued. This wraps whatever the listener threw and is handed to
 * `report()`; nothing in the package catches it.
 *
 * It exists because handing the ORIGINAL exception to `report()` is not the same as
 * reporting it. Laravel's handler skips a documented set outright — a listener that ran
 * `firstOrFail()`, `Gate::authorize()`, `validate()` or `abort()` throws something in that
 * set, and reporting it is a silent no-op. A failed alert that is itself unreported is the
 * failure this whole area exists to end, one level up. A package-owned class is in no
 * host's ignore list, so the signal arrives.
 */
final class UnreadablePayloadListenerFailed extends RuntimeException
{
    public static function for(string $source, Throwable $previous): self
    {
        return new self(
            "A listener on the unreadable-payload event for webhook source [{$source}] failed: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
