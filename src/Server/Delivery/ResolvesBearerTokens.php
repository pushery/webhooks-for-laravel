<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Delivery;

use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\PendingWebhook;

/**
 * Names the bearer token a delivery sends at the moment of each attempt.
 *
 * A receiver behind an API gateway can ask every request for a key it issued, sent as `Authorization: Bearer`, beside
 * the signature of the delivery. A delivery dispatched with {@see PendingWebhook::resolveBearerTokenUsing()} carries no
 * token in the queue: every attempt, a resend included, makes the class from the container and asks it for the token
 * of that moment, so an attempt after the receiver replaced its key sends the new one.
 *
 * The delivery is passed whole, so the class can read what it was dispatched with, its URL and its `meta` above all,
 * to find whose token it is.
 */
interface ResolvesBearerTokens
{
    /** The token to send with this attempt, as it stands now: one or more visible ASCII characters. */
    public function tokenFor(WebhookDeliveryData $delivery): string;
}
