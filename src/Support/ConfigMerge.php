<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

/**
 * Lays a host's published configuration OVER the shipped defaults, all the way down.
 *
 * Laravel's `mergeConfigFrom()` is an `array_merge` at the top level only. This package
 * ships twelve top-level keys and every one of them is a deep tree, so a host that
 * publishes the file and keeps only the block it cares about replaces that entire layer:
 * every sibling key it trimmed away becomes undefined, and every key a later release adds
 * to that layer never reaches it. Nothing reports either — a new safety default simply
 * does not take effect.
 *
 * Recursing is not enough on its own. `array_replace_recursive` merges numerically indexed
 * arrays BY INDEX, so a host narrowing `dashboard.windows` to `['7d']` gets back
 * `['7d', '7d', '30d']` — two windows it never asked for, one of them a value it removed
 * on purpose. A list is the value an operator SETS, not a container to descend into, so a
 * list is replaced whole and never merged. There are ten of them in the shipped file, and
 * `core.ssrf.allowed_hosts` is the one where getting this wrong is a security question
 * rather than a cosmetic one.
 *
 * `array_is_list([])` is true, which makes an empty array a leaf as well. That is the
 * behavior you want: an empty list is a host saying "none", and descending into it could
 * only ever re-introduce what it emptied.
 *
 * An explicit `null` still wins, because null is a value like any other here. That keeps
 * the documented way to switch a brake off — writing `=> null` — working exactly as the
 * shipped file describes it. Deleting the key is a different act and now means "I have no
 * opinion", which is what publishing a trimmed block always looked like it meant.
 *
 * @internal
 */
final class ConfigMerge
{
    /**
     * @param  array<array-key, mixed>  $package  the shipped defaults
     * @param  array<array-key, mixed>  $published  whatever the host has in place
     * @return array<array-key, mixed>
     */
    public static function tree(array $package, array $published): array
    {
        foreach ($published as $key => $value) {
            $default = $package[$key] ?? null;

            $package[$key] = is_array($value) && is_array($default)
                && ! array_is_list($value) && ! array_is_list($default)
                    ? self::tree($default, $value)
                    : $value;
        }

        return $package;
    }
}
