<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Events;

use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;

/**
 * An outbox entry is still not delivered webhooks.server.outbox.alarm_after_hours after it was
 * recorded. Fires once per entry, at the first failed attempt past that line, and the outbox keeps
 * trying: the event is an alarm for somebody to look at the receiver, not an end.
 */
final readonly class WebhookOutboxEntryOverdue
{
    public function __construct(
        public WebhookOutboxEntry $entry,
    ) {}
}
