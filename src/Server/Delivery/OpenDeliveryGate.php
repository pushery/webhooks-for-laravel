<?php

declare(strict_types=1);

namespace Webhooks\Server\Delivery;

use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * The Server layer's own gate: everything the engine was asked to send, it sends.
 * A consumer driving `Webhooks\Server\PendingWebhook` directly owns the decision
 * to enqueue, and the engine has no registry of endpoints to re-check it against.
 * The Platform layer replaces this binding with one that does.
 *
 * @internal
 */
final class OpenDeliveryGate implements DeliveryGate
{
    public function refusalFor(WebhookDeliveryData $data): ?string
    {
        return null;
    }
}
