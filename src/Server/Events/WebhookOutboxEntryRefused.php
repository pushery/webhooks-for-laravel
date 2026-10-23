<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Events;

use Pushery\Webhooks\Core\Http\TransportResponse;
use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;
use Throwable;

/**
 * An outbox entry ended without success because it was refused: the receiver answered with a
 * status a retry will not change, or the delivery gate refused to send it. Fires once per entry,
 * after the entry is stored as refused.
 *
 * The entry keeps its place in its stream, so the later entries of the stream wait. A listener
 * here is the usual place to decide what happens next: `Outbox::skip()` lets them go out,
 * `Outbox::resend()` sends this entry again.
 */
final readonly class WebhookOutboxEntryRefused
{
    public function __construct(
        public WebhookOutboxEntry $entry,
        public ?TransportResponse $response = null,
        public ?Throwable $exception = null,
    ) {}
}
