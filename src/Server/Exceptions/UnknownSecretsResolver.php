<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Exceptions;

use InvalidArgumentException;
use Pushery\Webhooks\Server\Signing\ResolvesDeliverySecrets;

/**
 * Thrown when the class a delivery resolves its secrets with does not resolve to a {@see ResolvesDeliverySecrets} — a
 * misconfiguration on the sending side, surfaced rather than sending the delivery unsigned.
 */
final class UnknownSecretsResolver extends InvalidArgumentException
{
    public static function for(string $class): self
    {
        return new self("The secrets resolver [{$class}] must implement Pushery\\Webhooks\\Server\\Signing\\ResolvesDeliverySecrets.");
    }
}
