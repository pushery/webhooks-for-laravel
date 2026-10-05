<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Erasure;

use Pushery\Webhooks\WebhookManager;

/**
 * What {@see WebhookManager::forgetEvents()} removed, so a caller reports a deletion only as far
 * as it happened.
 */
final readonly class ForgottenEvents
{
    public function __construct(
        /** Delivery-log rows deleted, redeliveries of the events included. */
        public int $deliveries,
        /** Offloaded payload objects deleted from their disk. */
        public int $objects,
        /**
         * Offloaded payload objects left in place because a row the call did not delete still
         * points at them: the objects are content-addressed, so another event with identical
         * bytes, or an inbound call, shares one. They hold no more than that row does.
         */
        public int $objectsKept,
    ) {}
}
