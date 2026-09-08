<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Support;

use Closure;
use RuntimeException;

/**
 * The endpoints the acting reader may READ but does not own.
 *
 * The delivery panel scopes to the acting tenant through {@see SubscriptionScope}, comparing the
 * denormalized owner pair on each delivery row. That answers one question — *does this row belong
 * to this owner?* — and an application often has a second one: *may this person see this row?*
 * The two agree until something is SHARED.
 *
 * The case this exists for, from a consuming application: a destination belongs to one user and
 * is shared with an organization. A member of that organization may see what was sent to it and
 * may not replay it. Under owner scoping alone that member sees nothing, because they are not the
 * owner of the row — and no ability the host could define changes that, since the constraint is
 * in the WHERE clause rather than in a policy.
 *
 * So the host answers the question it is the only one able to answer, and answers it as a set of
 * ids rather than as a predicate: a closure handed the query could drop the owner scoping, and
 * then the panel's central promise would depend on host code. Here the resolver can only ADD, and
 * what it adds is visible in one place.
 *
 * Registered from a service provider, like the tenant resolver beside it:
 *
 *     ReadableEndpoints::resolveUsing(
 *         fn (): array => auth()->user()?->sharedDestinationIds() ?? [],
 *     );
 *
 * With no resolver registered this returns an empty list and every scoping in the panel is
 * exactly what it was — the seam is inert until somebody uses it, which is the only acceptable
 * default for something that widens a read.
 *
 * It widens READING and nothing else, and that is still true of THIS resolver: declaring an
 * endpoint readable grants no right to replay from it. A host that wants to grant both says so
 * twice, through {@see ReplayableEndpoints} as well — two resolvers rather than one flag read
 * in two places, because seeing what was sent and sending it again are different permissions
 * and a real policy answers them apart.
 *
 * With only this one registered, replay stays owner-scoped exactly as before.
 */
final class ReadableEndpoints
{
    private static ?Closure $resolver = null;

    /**
     * Register the host's answer. The closure is called per resolution, never cached: membership
     * changes mid-session, and a reader whose access was withdrawn must not keep it until they
     * navigate.
     */
    public static function resolveUsing(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Drop the registered resolver — the panel falls back to owner scoping alone.
     */
    public static function forget(): void
    {
        self::$resolver = null;
    }

    /**
     * The endpoint ids the acting reader may read beyond the ones they own, newest answer first
     * hand rather than from a cache.
     *
     * @return list<int>
     */
    public static function ids(): array
    {
        if (! self::$resolver instanceof Closure) {
            return [];
        }

        return self::normalize((self::$resolver)());
    }

    /**
     * A resolver may return anything; only a list of ids is usable. A wrong shape is refused
     * loudly rather than coerced, because both plausible coercions are wrong in the dangerous
     * direction: dropping unreadable entries would silently narrow, and casting them would
     * silently widen onto whatever id the cast produced.
     *
     * @return list<int>
     */
    private static function normalize(mixed $resolved): array
    {
        if ($resolved === null) {
            return [];
        }

        if (! is_array($resolved)) {
            throw new RuntimeException(
                'A ReadableEndpoints resolver must return a list of endpoint ids or null, got '
                .get_debug_type($resolved).'.'
            );
        }

        $ids = [];

        foreach ($resolved as $id) {
            if (! is_int($id)) {
                throw new RuntimeException(
                    'A ReadableEndpoints resolver must return integer endpoint ids, got '
                    .get_debug_type($id).'.'
                );
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
