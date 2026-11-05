<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Pushery\Webhooks\Models\WebhookSubscription;

/**
 * Fired when an endpoint is pointed at another URL, other event types or another name, naming who
 * changed it and which of the three changed.
 *
 * A changed URL redirects every delivery that follows, so it is the edit an audit most often has
 * to reconstruct. The model's own `updated` event cannot answer it: it carries no actor, and it
 * fires on every failure count and health score the package writes as well.
 *
 * `$changed` names the attributes, never their values. The subscription carries the values now,
 * and what they were before is the listener's to keep where it needs them.
 *
 * Switching an endpoint on or off fires {@see WebhookEndpointEnabled} and
 * {@see WebhookEndpointDisabled} instead. The actor is null whenever no user is authenticated —
 * see {@see WebhookEndpointRegistered}.
 */
final readonly class WebhookEndpointUpdated
{
    /**
     * @param  list<'event_types'|'name'|'url'>  $changed  the attributes the update changed
     */
    public function __construct(
        public WebhookSubscription $subscription,
        public array $changed,
        public ?Authenticatable $actor,
    ) {}
}
