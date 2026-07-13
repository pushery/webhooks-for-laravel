<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * Generates an Ed25519 keypair in the exact base64 encoding {@see Ed25519Scheme}
 * consumes: a 32-byte public key and libsodium's 64-byte secret key, each base64
 * encoded with its conventional prefix. The `whpk_`/`whsk_` prefixes label which is
 * which at a glance and are stripped transparently by the scheme, so the raw base64
 * (without a prefix) is equally valid.
 *
 * The public key is safe to publish (statically or via a JWKS endpoint); the secret
 * key signs outgoing deliveries and must be kept confidential.
 */
final class Ed25519Keys
{
    /**
     * A fresh keypair, base64-encoded and prefixed.
     *
     * @return array{public: string, secret: string}
     */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => Ed25519Scheme::PUBLIC_PREFIX.base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => Ed25519Scheme::SECRET_PREFIX.base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }
}
