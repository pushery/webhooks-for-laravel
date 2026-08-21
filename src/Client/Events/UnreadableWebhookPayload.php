<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Pushery\Webhooks\Client\Models\WebhookCall;

/**
 * Fired when an authentic delivery arrived in a format nothing could read: the signature
 * verified, the bytes are there, and no decoder produced a payload from them. The call is
 * still stored and still answered with the config's success response, because refusing an
 * authentic delivery would ask the producer to retry bytes that will fail the same way.
 *
 * This exists because the alternative is silence. A handler handed an empty payload finds
 * nothing to do, marks the call processed and returns 200, and the producer never repeats a
 * delivery it was told succeeded — a total loss that looks like success from both ends.
 *
 * It carries the STORED CALL rather than the request, and that is a correctness requirement
 * rather than a preference: a listener may be queued, an `Illuminate\Http\Request` holds the
 * framework's user and route resolvers as closures, and a closure cannot be serialized. An
 * event that cannot reach a queue would throw on the way to one — destroying the very delivery
 * it announces. Everything a listener needs is on the row: `$call->body()` returns the exact
 * bytes, `body_sha256` fingerprints them, and `$call->headers` carries whatever `store_headers`
 * kept. The declared content type travels beside it because it is the reason the body was not
 * read, and `store_headers` is empty by default.
 *
 * That row travels whole into a queued listener's job payload, body included. On a queue driver
 * with a message-size limit, keep such a listener synchronous, or read what is needed here and
 * dispatch a job carrying only the call's id.
 *
 * ⚠️ IT CARRIES THE CONFIG'S NAME, NOT THE CONFIG, for a reason the paragraph above missed while
 * it was busy with the request. A WebhookConfig holds the signing secret and the rotation secret
 * in cleartext, and this row travels into a queued listener's job payload — so the config went
 * with it, into the queue store, and into the log of any listener that recorded the event. That
 * needed no queue either: a reporter serializing the event after a listener threw did the same.
 * `WebhookConfig::forName($event->source)` returns the whole config, read from configuration
 * rather than from a payload that travelled.
 */
final class UnreadableWebhookPayload
{
    use Dispatchable;

    public function __construct(
        public WebhookCall $call,
        public string $source,
        public ?string $contentType,
    ) {}
}
