<?php

declare(strict_types=1);

namespace Webhooks\Events;

use Webhooks\Models\WebhookSubscription;

/**
 * Fired when an endpoint is disabled automatically after too many consecutive
 * failures (the circuit breaker). Notify the owner so they can fix and re-enable it.
 */
final readonly class WebhookEndpointAutoDisabled
{
    public function __construct(
        public WebhookSubscription $subscription,
    ) {}
}
