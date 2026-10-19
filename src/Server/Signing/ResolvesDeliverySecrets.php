<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Signing;

use Pushery\Webhooks\Core\Signing\SecretSet;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\PendingWebhook;

/**
 * Names the secrets a delivery signs with at the moment of each attempt.
 *
 * A delivery signs with the secrets it was queued with, sealed into its job, for as long as it is retried. A receiver
 * that rotates its secret while a delivery waits then refuses every further attempt, and in an ordered stream that
 * delivery holds every one behind it until it is resent, still with the old secrets. A delivery dispatched with
 * {@see PendingWebhook::resolveSecretsUsing()} carries no secret in the queue instead: every attempt, a resend
 * included, makes the class from the container and asks it for the secrets of that moment.
 *
 * The delivery is passed whole, so the class can read what it was dispatched with, its URL and its `meta` above all,
 * to find whose secrets they are. To send a delivery unsigned, dispatch it with `doNotSign()`; this always signs.
 */
interface ResolvesDeliverySecrets
{
    /** The secrets to sign this attempt with, as they stand now. */
    public function secretsFor(WebhookDeliveryData $delivery): SecretSet;
}
