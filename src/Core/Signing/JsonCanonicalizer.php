<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

use JsonException;
use stdClass;

/**
 * Opt-in canonical JSON serializer, applied to a payload array before it is
 * signed so that the signed bytes and the sent bytes are always identical. Keys
 * are sorted recursively by their bytes and there is no insignificant whitespace,
 * giving a deterministic body regardless of the array's insertion order. Byte
 * order is a total order, so an id map reads "10" before "9" as RFC 8785 has it.
 *
 * Signing the exact bytes you send is already correct without this; it exists
 * only for producers who additionally want an order-independent body (e.g. to
 * match a receiver that re-canonicalizes). It is never applied automatically.
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
     * One value of the payload: a nested array sorted the same way, a plain object sorted like an array and kept an
     * object, so an empty one still encodes as `{}`, and any other value as it is.
     *
     * @param  mixed  $item  a value of the payload, which may be any JSON value
     * @return mixed the nested array or object sorted, or the value unchanged
     */
    private function sortItem(mixed $item): mixed
    {
        if ($item instanceof stdClass) {
            return (object) $this->sortRecursive(get_object_vars($item));
        }

        return is_array($item) ? $this->sortRecursive($item) : $item;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        $sorted = array_map($this->sortItem(...), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
