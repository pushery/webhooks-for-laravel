<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\WebhookManager;

/**
 * Fired when {@see WebhookManager::enable()} switches an endpoint back on, naming who did it.
 *
 * The counterpart of {@see WebhookEndpointDisabled}, and of {@see WebhookEndpointAutoDisabled}:
 * an endpoint the circuit breaker switched off comes back through enable() as well, so this is
 * also the signal that one is receiving deliveries again.
 *
 * It fires only when the call changed the endpoint's state. On an endpoint that was on already the
 * call still clears its failure streak, and fires nothing.
 *
 * The actor is null whenever no user is authenticated; see {@see WebhookEndpointRegistered} for
 * what that means.
 */
final readonly class WebhookEndpointEnabled
{
    public function __construct(
        public WebhookSubscription $subscription,
        public ?Authenticatable $actor,
    ) {}
}
