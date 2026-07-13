<?php

declare(strict_types=1);

namespace Webhooks\Server\Exceptions;

use RuntimeException;

/**
 * Thrown when the Server is configured to sign asymmetrically but no key material is
 * available. Signing must never degrade silently — a delivery that quietly went out
 * under the wrong key (or unsigned) is worse than one that never left — so the
 * misconfiguration surfaces here, loudly, at the moment a call is built.
 */
final class MissingSigningKey extends RuntimeException
{
    public static function ed25519(): self
    {
        return new self(
            'Ed25519 delivery signing is enabled (webhooks.server.signing.ed25519.enabled) but no secret key is '
            .'configured. Set webhooks.server.signing.ed25519.secret_key (WEBHOOKS_ED25519_SECRET_KEY) to the base64 '
            .'secret key from `php artisan webhooks:ed25519-keygen`, or switch the flag off.'
        );
    }
}
