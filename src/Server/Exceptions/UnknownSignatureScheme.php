<?php

declare(strict_types=1);

namespace Webhooks\Server\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a delivery's configured signature scheme class does not resolve to a
 * {@see SignatureScheme} — a misconfiguration on the sending
 * side, surfaced early rather than producing a broken signature.
 */
final class UnknownSignatureScheme extends InvalidArgumentException
{
    public static function for(string $class): self
    {
        return new self("The signature scheme [{$class}] must implement Webhooks\\Core\\Signing\\SignatureScheme.");
    }
}
