<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Events;

use Pushery\Webhooks\Server\Data\WebhookDeliveryData;

/**
 * A delivery is about to be QUEUED: fired synchronously at dispatch time, before any
 * HTTP work and before the first attempt exists. A listener observes the delivery on its
 * way into the queue and cannot change it: the data is readonly and nothing reads what a
 * listener returns. Throwing is the one way to stop it, and that aborts the caller's
 * dispatch with it.
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
