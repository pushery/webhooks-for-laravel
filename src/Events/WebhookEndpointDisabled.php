<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\WebhookManager;

/**
 * Fired when {@see WebhookManager::disable()} switches an endpoint off, naming who did it.
 *
 * Switching an endpoint off is a decision somebody made: a tenant in the portal, an operator in
 * the console, or the host's own code. A host that keeps its own record of an endpoint, a
 * destination that rules point at, a status on a settings page, follows the endpoint from here.
 * The circuit breaker switches an endpoint off on its own and fires
 * {@see WebhookEndpointAutoDisabled} instead: a different cause, and a different sentence to the
 * tenant.
 *
 * It fires only when the call changed the endpoint's state. On an endpoint that was off already,
 * by hand or by the breaker, the call fires nothing.
 *
 * The actor is null whenever no user is authenticated; see {@see WebhookEndpointRegistered} for
 * what that means.
 */
final readonly class WebhookEndpointDisabled
{
    public function __construct(
        public WebhookSubscription $subscription,
        public ?Authenticatable $actor,
    ) {}
}
