<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

use JsonException;

/**
 * Opt-in canonical JSON serializer, applied to a payload array BEFORE it is
 * signed so that the signed bytes and the sent bytes are always identical. Keys
 * are sorted recursively and there is no insignificant whitespace, giving a
 * deterministic body regardless of the array's insertion order.
 *
 * Signing the exact bytes you send is already correct without this; it exists
 * only for producers who additionally want an order-independent body (e.g. to
 * match a receiver that re-canonicalises). It is never applied automatically.
 *
 * @internal
 */
final readonly class JsonCanonicalizer
{
    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws JsonException
     */
    public function canonicalize(array $payload): string
    {
        return json_encode(
            $this->sortRecursive($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        $sorted = array_map(
            fn (mixed $item): mixed => is_array($item) ? $this->sortRecursive($item) : $item,
            $value,
        );

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
