<?php

declare(strict_types=1);

namespace Webhooks\Server\Events;

use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * A delivery is about to be QUEUED: fired synchronously at dispatch time, before any
 * HTTP work and before the first attempt exists. Lets a listener veto or annotate a
 * delivery on its way into the queue.
 *
 * The one delivery-scoped event in this namespace — every other event here belongs to a
 * single HTTP attempt ({@see WebhookAttemptStarting} and friends), which is why they
 * carry an attempt number and this one does not.
 */
final readonly class WebhookDeliveryDispatching
{
    public function __construct(
        public WebhookDeliveryData $data,
    ) {}
}
