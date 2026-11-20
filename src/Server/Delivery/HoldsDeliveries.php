<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Delivery;

use Pushery\Webhooks\Server\Data\WebhookDeliveryData;

/**
 * A gate that can also hold a delivery back for a while, without refusing it.
 *
 * Asked after {@see DeliveryGate::refusalFor()}, the moment before a delivery is sent. A sibling of that contract rather
 * than a method on it, because an application can implement `DeliveryGate`, and a method added to it would break that
 * implementation. The Platform layer's gate holds the deliveries of an endpoint whose attempts keep failing.
 *
 * @internal
 */
interface HoldsDeliveries
{
    /**
     * How many seconds this delivery waits before it is tried, or null when it may go now.
     */
    public function holdFor(WebhookDeliveryData $data): ?int;
}
