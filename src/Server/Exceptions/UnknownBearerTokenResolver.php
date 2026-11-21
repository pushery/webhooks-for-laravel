<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Exceptions;

use InvalidArgumentException;
use Pushery\Webhooks\Server\Delivery\ResolvesBearerTokens;

/**
 * Thrown when the class a delivery resolves its bearer token with does not resolve to a {@see ResolvesBearerTokens} — a
 * misconfiguration on the sending side, surfaced rather than sending the delivery without its token.
 */
final class UnknownBearerTokenResolver extends InvalidArgumentException
{
    public static function for(string $class): self
    {
        return new self("The bearer token resolver [{$class}] must implement Pushery\\Webhooks\\Server\\Delivery\\ResolvesBearerTokens.");
    }
}
