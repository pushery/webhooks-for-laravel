<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

/**
 * How {@see PlainHmacScheme} writes its HMAC-SHA256 digest into the signature header: as lowercase
 * hex, the default, or as standard Base64 with padding, the way Shopify and WooCommerce send it.
 *
 * A source picks one with `signature_encoding` in its Client config. A signature in the other
 * encoding never matches, and that refusal looks exactly like a wrong secret.
 */
enum SignatureEncoding: string
{
    case Hex = 'hex';
    case Base64 = 'base64';

    /**
     * The raw digest bytes, written in this encoding.
     */
    public function encode(string $digest): string
    {
        return match ($this) {
            self::Hex => bin2hex($digest),
            self::Base64 => base64_encode($digest),
        };
    }
}
