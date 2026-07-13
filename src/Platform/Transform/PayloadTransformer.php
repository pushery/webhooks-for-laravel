<?php

declare(strict_types=1);

namespace Webhooks\Platform\Transform;

/**
 * Reshapes an event payload for a specific endpoint before it is signed and sent.
 * An implementation is pure and deterministic: the same payload, rules and version
 * always yield the same result, so a redelivery reproduces the exact bytes.
 */
interface PayloadTransformer
{
    /**
     * @param  array<array-key, mixed>  $payload  the event data to reshape
     * @param  array<array-key, mixed>|null  $rules  the declarative mapping, or null for no field changes
     * @param  string|null  $version  the payload version to stamp, or null for none
     * @return array<array-key, mixed>
     */
    public function transform(array $payload, ?array $rules, ?string $version): array;
}
