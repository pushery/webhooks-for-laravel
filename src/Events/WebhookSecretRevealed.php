<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Pushery\Webhooks\Models\WebhookSubscription;

/**
 * Fired when the self-service portal reveals an endpoint's signing secret, naming who saw it.
 *
 * Seeing a signing secret is the step before every misuse of one, and the question an audit asks
 * afterwards is who saw it and when. The portal shows the secret for `secret_reveal_ttl` seconds,
 * after a registration and whenever the tenant asks for it, and both fire this event. The secret a
 * rotation shows is recorded by {@see WebhookSecretRotated}.
 *
 * The secret does not travel on the event, for the reason {@see WebhookSecretRotated} gives: an
 * event is broadcast to every listener, serialized into queue payloads and often logged whole.
 *
 * The actor is null whenever no user is authenticated — see {@see WebhookEndpointRegistered}.
 */
final readonly class WebhookSecretRevealed
{
    public function __construct(
        public WebhookSubscription $subscription,
        public ?Authenticatable $actor,
    ) {}
}
