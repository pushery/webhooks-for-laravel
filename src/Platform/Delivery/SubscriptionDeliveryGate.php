<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Delivery;

use Pushery\Webhooks\Database\PartitionManager;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Delivery\DeliveryGate;

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
 * The same holds for an endpoint whose url was changed: a tenant who moves it to a new
 * address, say because the old domain was given up, expects nothing more to go to the old
 * one. A delivery addressed to a url the endpoint no longer has is refused, not readdressed:
 * a gate decides whether a delivery goes, never where. A replay sends it to the new url.
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

        $subscription = WebhookSubscription::model()::query()->find($subscriptionId);

        if (! $subscription instanceof WebhookSubscription) {
            return 'The endpoint was deleted while this delivery was queued; it was not sent.';
        }

        if (! $subscription->is_active) {
            return 'The endpoint was disabled while this delivery was queued; it was not sent.';
        }

        if ($subscription->url !== $data->url) {
            return 'The endpoint was given another URL while this delivery was queued; it was not sent.';
        }

        if ($this->erased($data)) {
            return 'The delivery was erased while it was queued; it was not sent.';
        }

        return null;
    }

    /**
     * Whether the log row this delivery was written as is gone, which is what forgetting its event
     * leaves behind: the job still carries the body, and sending it after the erasure would hand
     * the data out that the caller was just told is deleted.
     *
     * Looked up with the row's own partition key, as the lifecycle subscriber does, so the planner
     * reads one partition rather than probing every one.
     */
    private function erased(WebhookDeliveryData $data): bool
    {
        $deliveryId = $data->meta['delivery_id'] ?? null;

        if (! is_string($deliveryId)) {
            return false;
        }

        $query = WebhookDelivery::model()::query()->withoutGlobalScopes()->whereKey($deliveryId);
        $createdAt = $data->meta['delivery_created_at'] ?? null;

        if (is_string($createdAt)) {
            $query->where('created_at', $createdAt);
        }

        if ($query->exists()) {
            return false;
        }

        // A partition drain moves rows into a month it attaches last, and a row there is moved,
        // not erased: refused, the delivery would never be sent.
        $moved = is_string($createdAt) ? new PartitionManager()->movedDelivery($deliveryId, $createdAt) : null;

        return ! $moved instanceof WebhookDelivery;
    }
}
