<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Fired when an endpoint is deleted, naming who deleted it.
 *
 * The endpoint and its delivery log are gone by the time a listener runs, so the event carries
 * what an audit line needs to say which endpoint it was: its key, its name and the URL it
 * delivered to. Nothing of its signing secret travels with it.
 *
 * The actor is null whenever no user is authenticated — see {@see WebhookEndpointRegistered}.
 */
final readonly class WebhookEndpointDeleted
{
    public function __construct(
        public int $subscriptionId,
        public ?string $name,
        public string $url,
        public ?Authenticatable $actor,
    ) {}
}
