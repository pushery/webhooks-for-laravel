<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Transform;

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
     * Move each present old key to its new name, resolving every move against the original
     * payload rather than against the half-renamed one.
     *
     * This used to write into the result as it went — `$result[$to] = $result[$from]` — and
     * a rename that reads what an earlier rename just wrote is a different operation from the
     * one anybody configured. `['a' => 'b', 'b' => 'c']` moved `a` into `b`, then found its own
     * output there and moved it on to `c`: one key survived out of two, and the value that
     * started in `b` was gone. The chain ate itself.
     *
     * Reading sources from the input and writing to a separate result makes the map order-free
     * and makes a chain mean what it looks like. A swap (`['a' => 'b', 'b' => 'a']`) now works
     * too, which it could not before.
     *
     * A collision is refused rather than resolved: renaming onto a key the payload already has,
     * or two renames onto the same target, drops a value that the receiver has no way to know
     * was ever there — and the transformed body is the body that gets signed and logged, so
     * there is no trace to find afterwards. Refusing leaves the field where it was, which is
     * the outcome a reader can see and correct.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function applyRename(array $payload, mixed $map): array
    {
        if (! is_array($map)) {
            return $payload;
        }

        $moved = [];
        $taken = [];

        foreach ($map as $from => $to) {
            if (! is_string($to) || $to === '') {
                continue;
            }
            if (! array_key_exists($from, $payload)) {
                continue;
            }

            // The target is occupied by something this rename would destroy: a key the payload
            // still carries and is not itself being moved away, or a target a previous rename
            // already claimed.
            $occupied = array_key_exists($to, $taken)
                || (array_key_exists($to, $payload) && ! array_key_exists($to, $map));

            if ($occupied) {
                continue;
            }

            $moved[(string) $from] = $to;
            $taken[$to] = true;
        }

        // The renamed fields are APPENDED, in the map's order, and the rest keep their places.
        // That is what the previous implementation produced for every input it got right, so
        // this change is about the chain and the collision and about nothing else -- a body
        // whose key order moves is a body whose bytes move, and its signature with them.
        $result = [];

        foreach ($payload as $key => $value) {
            if (! array_key_exists((string) $key, $moved)) {
                $result[$key] = $value;
            }
        }

        foreach ($moved as $from => $to) {
            $result[$to] = $payload[$from];
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
