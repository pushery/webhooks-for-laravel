<?php

declare(strict_types=1);

namespace Webhooks\Events;

use Webhooks\Models\WebhookDelivery;

/**
 * Fired when an endpoint's per-minute allowance was exceeded and the delivery was
 * DEFERRED rather than dropped: the log row exists and the delivery is queued, it will
 * simply be sent once the endpoint's window has room for it.
 *
 * The event is the operator's signal that the throttle is biting — the alternative, a
 * silently vanished event, leaves nothing at all to look at when a customer reports a
 * webhook that never arrived.
 */
final readonly class WebhookDeliveryRateLimited
{
    public function __construct(
        public WebhookDelivery $delivery,
        public int $delaySeconds,
    ) {}
}
