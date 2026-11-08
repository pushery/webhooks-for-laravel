<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Pushery\Webhooks\Models\WebhookSubscription;

/**
 * Fired when the payload version or the transform of an endpoint changes, naming who changed it and
 * which of the two changed.
 *
 * Both decide what the receiver gets: the version, in which form a delivery arrives, and the
 * transform, which fields it carries, leaves out or renames. A changed transform can drop a field
 * a receiver reads, so an audit asks about it as it asks about a changed URL, which
 * {@see WebhookEndpointUpdated} reports. The model's own `updated` event cannot answer it: it
 * carries no actor, and it fires on every failure count and health score the package writes as
 * well.
 *
 * `$changed` names the attributes, never their values. The transform is compared as a rule set,
 * so the same rules with their keys in another order are no change.
 *
 * The actor is null whenever no user is authenticated — see {@see WebhookEndpointRegistered}.
 */
final readonly class WebhookEndpointPayloadUpdated
{
    /**
     * @param  list<'payload_version'|'transform'>  $changed  the attributes the update changed
     */
    public function __construct(
        public WebhookSubscription $subscription,
        public array $changed,
        public ?Authenticatable $actor,
    ) {}
}
