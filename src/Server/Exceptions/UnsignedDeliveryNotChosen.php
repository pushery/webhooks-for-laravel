<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Exceptions;

use RuntimeException;

/**
 * A delivery that names no secret, no secrets resolver and no `doNotSign()`, so it goes out unsigned although nobody
 * chose that. Reported to the exception handler while `webhooks.server.signing.unsigned` is `report`, its default,
 * and thrown instead of building the delivery where it is `refuse`. `doNotSign()` sends unsigned on purpose and is
 * never reported.
 */
final class UnsignedDeliveryNotChosen extends RuntimeException
{
    public static function to(string $host): self
    {
        return new self("The webhook delivery to [{$host}] names no secret, no secrets resolver and no doNotSign(), so it goes out unsigned. Sign it with useSecret(), useSecrets() or resolveSecretsUsing(), or send it unsigned on purpose with doNotSign().");
    }
}
