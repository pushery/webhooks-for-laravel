<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Pushery\Webhooks\Models\WebhookDelivery;

/**
 * Fired once when a delivery ends without succeeding: it exhausted its retries, or it was
 * refused unsent because its endpoint was deleted, disabled or given another URL while it
 * waited. The delivery's status says which (`DeliveryStatus::Exhausted` or `Refused`), and
 * $reason says why. Wire your own listener to notify the endpoint owner, or broadcast it
 * (e.g. over Reverb) for a live dashboard — the package keeps no such dependency itself.
 */
final readonly class WebhookDeliveryFailed
{
    public function __construct(
        public WebhookDelivery $delivery,
        public string $reason,
    ) {}
}
