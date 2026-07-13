<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A tenant's morph identity — the (owner_type, owner_id) pair that owns a webhook
 * subscription and its denormalized deliveries. Because a subscription's owner is a
 * morphTo, tenant isolation must compare the WHOLE pair: two tenants that happen to
 * share an owner_id under different owner types are different tenants and must never
 * see each other's endpoints, deliveries or signing secrets.
 */
final readonly class TenantIdentity
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}

    /**
     * Derive the identity from an Eloquent model — its morph class and primary key,
     * exactly as a morphTo owner is stored on the row.
     */
    public static function fromModel(Model $model): self
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new RuntimeException(
                'A tenant model must expose an int or string primary key to own a webhook subscription.'
            );
        }

        return new self($model->getMorphClass(), $key);
    }

    /**
     * Whether this identity owns the row carrying the given (owner_type, owner_id)
     * columns. Both columns must match; a null owner — a global, owner-less row — is
     * owned by no tenant. The id compare is tolerant of the type the id arrives as: a
     * bigint owner_id comes back from PostgreSQL as a string while a resolver hands
     * back an int, so a genuine match is never denied on that difference alone.
     */
    public function owns(?string $ownerType, int|string|null $ownerId): bool
    {
        if ($ownerType === null || $ownerId === null) {
            return false;
        }

        return $this->type === $ownerType && (string) $this->id === (string) $ownerId;
    }

    /**
     * Whether two tenant identities are the same tenant — both columns equal.
     */
    public function equals(?self $other): bool
    {
        return $other instanceof self && $this->owns($other->type, $other->id);
    }
}
