<?php

declare(strict_types=1);

namespace Webhooks\Events;

use Webhooks\Models\WebhookDelivery;

/**
 * Fired when a delivery has exhausted its retries. Wire your own listener to
 * notify the endpoint owner, or broadcast it (e.g. over Reverb) for a live
 * dashboard — the package keeps no such dependency itself.
 */
final readonly class WebhookDeliveryFailed
{
    public function __construct(
        public WebhookDelivery $delivery,
        public string $reason,
    ) {}
}
