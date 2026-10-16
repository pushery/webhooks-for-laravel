<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Testing;

use Illuminate\Database\Eloquent\Collection;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;

/**
 * One webhook the {@see WebhookFake} recorded in place of sending it: one call to a method that
 * sends, whatever number of endpoints it was for.
 */
final readonly class FakedWebhook
{
    /**
     * @param  string|null  $eventType  the event type; null for a send through the Server layer that named none
     * @param  array<array-key, mixed>  $payload  the event data the call was given; for a send through the Server layer, its body decoded from JSON, or empty when the body is not JSON
     * @param  Collection<int, WebhookSubscription>  $subscriptions  the endpoints a call to the manager was for, which for `dispatch()` is every endpoint that listens, possibly none; empty for a send through the Server layer
     * @param  WebhookDeliveryData|null  $delivery  what a send through the Server layer would have queued, with its URL, its body and its headers; null for a call to the manager
     */
    public function __construct(
        public ?string $eventType,
        public array $payload,
        public Collection $subscriptions,
        public ?WebhookDeliveryData $delivery = null,
    ) {}

    /**
     * The record of a send through the Server layer: a `PendingWebhook`, or a delivery sent again.
     *
     * @internal
     */
    public static function sentThroughTheServer(WebhookDeliveryData $delivery): self
    {
        $decoded = json_decode($delivery->rawBody, true);

        return new self(
            eventType: $delivery->eventType,
            payload: is_array($decoded) ? $decoded : [],
            subscriptions: new Collection,
            delivery: $delivery,
        );
    }
}
