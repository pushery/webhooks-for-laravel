<?php

declare(strict_types=1);

namespace Webhooks\Platform\Transform;

/**
 * A safe, data-driven payload transformer. It applies a fixed, deterministic set of
 * declarative operations — there is no callable, expression or eval anywhere, so a
 * rule set is pure data an operator can store and audit.
 *
 * The rules are applied in a fixed order so the outcome never depends on key order
 * in the rule array:
 *
 *   1. include  — an allow-list of field names; only these survive.
 *   2. exclude  — a deny-list of field names; these are dropped.
 *   3. rename   — a map of old field name to new field name.
 *   4. rewrap   — nest the whole result under a single key.
 *
 * Finally, when a version is supplied it is stamped as a top-level `payload_version`
 * field so a receiver can tell which shape it was sent.
 */
final class DeclarativePayloadTransformer implements PayloadTransformer
{
    public function transform(array $payload, ?array $rules, ?string $version): array
    {
        $result = $payload;

        if ($rules !== null) {
            $result = $this->applyInclude($result, $rules['include'] ?? null);
            $result = $this->applyExclude($result, $rules['exclude'] ?? null);
            $result = $this->applyRename($result, $rules['rename'] ?? null);
            $result = $this->applyRewrap($result, $rules['rewrap'] ?? null);
        }

        if ($version !== null) {
            $result['payload_version'] = $version;
        }

        return $result;
    }

    /**
     * Keep only the allow-listed fields, preserving their original order.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function applyInclude(array $payload, mixed $include): array
    {
        if (! is_array($include)) {
            return $payload;
        }

        $allowed = $this->fieldNames($include);

        return array_filter(
            $payload,
            static fn (int|string $key): bool => in_array((string) $key, $allowed, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Drop every deny-listed field.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function applyExclude(array $payload, mixed $exclude): array
    {
        if (! is_array($exclude)) {
            return $payload;
        }

        $denied = $this->fieldNames($exclude);

        return array_filter(
            $payload,
            static fn (int|string $key): bool => ! in_array((string) $key, $denied, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Move each present old key to its new name, in the map's own order.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function applyRename(array $payload, mixed $map): array
    {
        if (! is_array($map)) {
            return $payload;
        }

        $result = $payload;

        foreach ($map as $from => $to) {
            if (! is_string($to)) {
                continue;
            }
            if (! array_key_exists($from, $result)) {
                continue;
            }
            $result[$to] = $result[$from];
            unset($result[$from]);
        }

        return $result;
    }

    /**
     * Nest the whole result under a single key.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function applyRewrap(array $payload, mixed $key): array
    {
        if (! is_string($key) || $key === '') {
            return $payload;
        }

        return [$key => $payload];
    }

    /**
     * Extract the string field names from a rule value, ignoring non-strings. A rule
     * that is not a list at all never reaches here — the callers pass it through
     * untouched — so the value is always an array by the time it is read.
     *
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private function fieldNames(array $value): array
    {
        $names = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $names[] = $item;
            }
        }

        return $names;
    }
}
