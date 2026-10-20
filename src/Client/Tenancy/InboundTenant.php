<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Tenancy;

use InvalidArgumentException;
use Pushery\Webhooks\Core\Signing\SecretSet;

/**
 * The tenant one inbound request is addressed to, with the secrets that sign for it.
 *
 * An {@see InboundTenantResolver} builds it from the request, typically from a path segment, and reads the secrets
 * from the tenant's own record. The key is stored on the delivery and goes in front of its dedupe key, so the same
 * delivery id sent to two tenants of one config is two deliveries, never a duplicate of each other.
 */
final readonly class InboundTenant
{
    /** The longest key the `tenant` column of `webhook_calls` holds. */
    public const int MAX_KEY_LENGTH = 191;

    public function __construct(
        public string $key,
        public string $secret,
        public ?string $previousSecret = null,
    ) {
        if (trim($key) === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('An inbound tenant needs a key of 1 to '.self::MAX_KEY_LENGTH.' bytes.');
        }
    }

    /** The secrets a delivery to this tenant is verified with, the previous one beside it during a rotation. */
    public function secrets(): SecretSet
    {
        if ($this->previousSecret === null || trim($this->previousSecret) === '') {
            return SecretSet::fromCurrent($this->secret);
        }

        return SecretSet::rotating($this->secret, $this->previousSecret);
    }
}
