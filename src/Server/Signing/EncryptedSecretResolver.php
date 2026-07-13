<?php

declare(strict_types=1);

namespace Webhooks\Server\Signing;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Webhooks\Core\Signing\SecretSet;
use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * The default resolver: the secret travels through the queue SEALED with the app
 * encrypter and is unsealed here, at handle time — never at rest in cleartext.
 * Use {@see self::seal()} when building the delivery data to encrypt a SecretSet.
 *
 * @internal
 */
final class EncryptedSecretResolver implements SecretResolver
{
    public function resolveFor(WebhookDeliveryData $data): ?SecretSet
    {
        if ($data->doNotSign || $data->encryptedSecret === null) {
            return null;
        }

        $tokens = Crypt::decrypt($data->encryptedSecret);

        if (! is_array($tokens) || ! isset($tokens['current']) || ! is_string($tokens['current'])) {
            throw new RuntimeException('The sealed webhook signing secret is malformed.');
        }

        $secrets = SecretSet::fromCurrent($tokens['current']);

        if (isset($tokens['previous']) && is_string($tokens['previous'])) {
            return $secrets->withPrevious($tokens['previous']);
        }

        return $secrets;
    }

    /**
     * Seal a SecretSet for safe transit through the queue store.
     */
    public static function seal(SecretSet $secrets): string
    {
        return Crypt::encrypt($secrets->all());
    }
}
