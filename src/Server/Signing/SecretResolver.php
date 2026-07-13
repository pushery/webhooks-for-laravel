<?php

declare(strict_types=1);

namespace Webhooks\Server\Signing;

use Webhooks\Core\Signing\SecretSet;
use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * Resolves the signing secrets for a delivery AT HANDLE TIME, so the raw secret
 * never has to sit in the serialized job payload. The default
 * {@see EncryptedSecretResolver} unseals an encrypted inline secret; the Platform
 * layer binds a resolver that loads a subscription's secret by id instead, keeping
 * subscription secrets out of the queue entirely.
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
