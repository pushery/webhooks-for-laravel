<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Exceptions;

use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\WebhookManager;
use RuntimeException;

/**
 * A single-subscription delivery ({@see WebhookManager::dispatchTo()}) was refused because
 * the named endpoint is not one the fan-out would have reached for this event: it is
 * inactive or auto-disabled, or it never subscribed to the event type.
 *
 * Refused rather than delivered, and refused rather than quietly skipped. Both of the
 * alternatives are worse in the same way — they are silent:
 *
 * Delivering anyway would send an endpoint an event it never asked for, which is the one
 * thing a subscription list is for. `dispatch()` cannot do it, so a targeted send must not
 * become the back door that can.
 *
 * Returning nothing would make the caller's next line the problem instead. A caller that
 * named one subscription and expects one delivery has to handle a null it did not ask
 * about, and the usual way that goes is that nobody does — the event vanishes and the
 * missing webhook is discovered by the customer.
 *
 * The reason is carried, not just the refusal, because the two causes want different
 * fixes: a disabled endpoint is re-enabled, an unsubscribed one has its event types
 * changed.
 */
final class SubscriptionNotListening extends RuntimeException
{
    public function __construct(
        public readonly WebhookSubscription $subscription,
        public readonly string $eventType,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Endpoint %d cannot receive %s: %s.',
            $subscription->id,
            $eventType,
            $reason,
        ));
    }
}
