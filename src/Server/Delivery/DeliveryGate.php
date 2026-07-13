<?php

declare(strict_types=1);

namespace Webhooks\Server\Delivery;

use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * The last word on whether a QUEUED delivery may still go out.
 *
 * A delivery's destination and secret are sealed into its payload at dispatch time,
 * so without this seam the job would keep POSTing to an endpoint that has since been
 * switched off — or deleted. That is not a nit: a circuit breaker that "opens" while
 * the whole backlog keeps firing has not broken the circuit, and an endpoint a tenant
 * deleted must never receive another byte of their customers' data.
 *
 * The Server layer knows nothing about subscriptions, so it ships an open gate; the
 * Platform layer binds the one that re-reads the endpoint (see
 * `Webhooks\Platform\Delivery\SubscriptionDeliveryGate`).
 */
interface DeliveryGate
{
    /**
     * The reason this delivery must not be sent, or null when it may proceed. The
     * reason is recorded on the delivery log, so it is written for a human reading it.
     */
    public function refusalFor(WebhookDeliveryData $data): ?string;
}
