<?php

declare(strict_types=1);

namespace Webhooks\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webhooks\Client\Models\WebhookCall;
use Webhooks\Client\WebhookConfig;

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
 */
final class UnreadableWebhookPayload
{
    use Dispatchable;

    public function __construct(
        public WebhookCall $call,
        public WebhookConfig $config,
        public ?string $contentType,
    ) {}
}
