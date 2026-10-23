<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Events;

use Pushery\Webhooks\Core\Http\TransportResponse;
use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;
use Throwable;

/**
 * The outbox gave an entry up: it made webhooks.server.outbox.max_attempts attempts, and each one
 * failed in a way a retry could have passed. Fires once per entry, after the entry is stored as
 * given up. With no maximum configured the outbox never gives up, and this never fires.
 *
 * The entry keeps its place in its stream, like a refused one, until `Outbox::resend()` sends it
 * again or `Outbox::skip()` lets the later entries go out.
 */
final readonly class WebhookOutboxEntryGivenUp
{
    public function __construct(
        public WebhookOutboxEntry $entry,
        public ?TransportResponse $response = null,
        public ?Throwable $exception = null,
    ) {}
}
