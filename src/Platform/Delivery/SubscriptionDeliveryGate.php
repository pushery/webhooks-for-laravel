<?php

declare(strict_types=1);

namespace Webhooks\Platform\Delivery;

use Webhooks\Models\WebhookSubscription;
use Webhooks\Server\Data\WebhookDeliveryData;
use Webhooks\Server\Delivery\DeliveryGate;

/**
 * Re-reads the endpoint a queued delivery was addressed to, immediately before it is
 * sent, and refuses the delivery when that endpoint is no longer eligible.
 *
 * The url and the sealed secret are baked into the delivery at dispatch time, so
 * without this re-read a backlog outlives the endpoint it was built for: the circuit
 * breaker flips is_active to false and the queued deliveries keep hammering the dead
 * endpoint for their whole retry budget, and an endpoint a tenant DELETED in the
 * self-service portal still receives every delivery already in flight — data egress
 * after deletion.
 *
 * Deliveries the Platform layer did not create (a consumer driving the engine
 * directly) carry no subscription id and are never refused.
 *
 * @internal
 */
final class SubscriptionDeliveryGate implements DeliveryGate
{
    public function refusalFor(WebhookDeliveryData $data): ?string
    {
        $subscriptionId = $data->meta['subscription_id'] ?? null;

        if (! is_int($subscriptionId) && ! is_string($subscriptionId)) {
            return null;
        }

        $subscription = WebhookSubscription::query()->find($subscriptionId);

        if (! $subscription instanceof WebhookSubscription) {
            return 'The endpoint was deleted while this delivery was queued; it was not sent.';
        }

        if (! $subscription->is_active) {
            return 'The endpoint was disabled while this delivery was queued; it was not sent.';
        }

        return null;
    }
}
