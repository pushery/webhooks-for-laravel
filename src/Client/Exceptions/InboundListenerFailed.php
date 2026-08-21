<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Exceptions;

use Pushery\Webhooks\Client\Events\InboundWebhookVerified;
use Pushery\Webhooks\Client\Events\UnreadableWebhookPayload;
use RuntimeException;
use Throwable;

/**
 * A listener on an inbound event threw, and the delivery it was announcing was authentic and
 * already safe. This wraps whatever the listener threw and is handed to `report()`; nothing in
 * the package catches it.
 *
 * It exists because handing the ORIGINAL exception to `report()` is not the same as reporting
 * it. Laravel's handler skips a documented set outright — a listener that ran `firstOrFail()`,
 * `Gate::authorize()`, `validate()` or `abort()` throws something in that set, and reporting it
 * is a silent no-op. A failed alert that is itself unreported is the failure this whole area
 * exists to end, one level up. A package-owned class is in no host's ignore list, so the signal
 * arrives.
 *
 * It covers both guarded inbound events — {@see UnreadableWebhookPayload} and
 * {@see InboundWebhookVerified} — because the failure mode and the reasoning above are identical
 * for them, and a second class would have been the same twelve lines drifting apart. The event's
 * short name travels in the message so the report says which one it was.
 */
final class InboundListenerFailed extends RuntimeException
{
    public static function for(string $event, string $source, Throwable $previous): self
    {
        return new self(
            "A listener on the {$event} event for webhook source [{$source}] failed: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
