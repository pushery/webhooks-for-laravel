<?php

declare(strict_types=1);

namespace Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Webhooks\Models\WebhookSubscription;

/**
 * Fired when an endpoint's signing secret is rotated, naming WHO rotated it.
 *
 * Rotating a signing secret is a security action taken by a person, and it is the one an
 * audit is most often reconstructed around: a secret changed, deliveries kept verifying
 * for the length of the rotation window, and the question afterwards is who did it and
 * when — not which delivery it affected.
 *
 * Neither secret travels on the event, and that is deliberate. An event is broadcast to
 * every listener, serialized into queue payloads and frequently logged wholesale; a
 * package that puts signing material on one has quietly moved the secret into every one of
 * those places. A listener that genuinely needs the value can read it from the
 * subscription it is handed.
 *
 * The actor is null whenever no user is authenticated — see
 * {@see WebhookEndpointRegistered} for what that means and why it is worth recording.
 */
final readonly class WebhookSecretRotated
{
    public function __construct(
        public WebhookSubscription $subscription,
        public ?Authenticatable $actor,
    ) {}
}
