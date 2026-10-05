<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Signing;

use Pushery\Webhooks\Core\Signing\SecretSet;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;

/**
 * Resolves the signing secrets for a delivery at handle time, so the raw secret
 * never has to sit in the serialized job payload. The bound {@see EncryptedSecretResolver}
 * unseals the secrets the delivery carries: the Platform layer seals an endpoint's current
 * secret, and during a rotation its previous one, into every delivery it queues. So whoever
 * holds both the queue and the application key can read them, and a delivery signs with the
 * secrets it was queued with for as long as it is retried.
 *
 * @internal
 */
interface SecretResolver
{
    /**
     * @return SecretSet|null the secrets to sign with, or null when the delivery is unsigned
     */
    public function resolveFor(WebhookDeliveryData $data): ?SecretSet;
}
