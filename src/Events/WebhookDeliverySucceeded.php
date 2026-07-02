<?php

declare(strict_types=1);

namespace Webhooks\Events;

use Webhooks\Models\WebhookDelivery;

/**
 * Fired after a delivery is accepted by the endpoint (a 2xx response).
 */
final readonly class WebhookDeliverySucceeded
{
    public function __construct(
        public WebhookDelivery $delivery,
    ) {}
}
