<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Support;

use Closure;
use RuntimeException;

/**
 * The endpoints the acting reader may REPLAY FROM but does not own.
 *
 * The sibling of {@see ReadableEndpoints}, one act further along, and the two are deliberately
 * separate rather than one list consulted twice. Seeing what was sent and sending it again are
 * different permissions in every application that has thought about it: a member of an
 * organization a destination is shared into may reasonably read its history, while causing a
 * fresh HTTP request to leave the installation under that destination's name is the kind of act
 * a host reserves for an administrator.
 *
 * The case this exists for, from a consuming application: a destination belongs to one user and
 * is shared with an organization. Membership answers *may this person look?*; being an
 * administrator of that organization answers *may this person replay?* Under owner scoping alone
 * the second question has no way to say yes — the administrator does not own the row — so the
 * button rendered and then refused, which teaches a reader that the screen is unreliable.
 *
 * Registered from a service provider, like the resolvers beside it:
 *
 *     ReplayableEndpoints::resolveUsing(
 *         fn (): array => auth()->user()?->administeredDestinationIds() ?? [],
 *     );
 *
 * With no resolver registered this returns an empty list and replay is exactly what it was:
 * owner-scoped. The seam is inert until somebody uses it, which is the only acceptable default
 * for something that widens who may act.
 *
 * Three properties are load-bearing rather than incidental:
 *
 * - **It only ever ADDS.** The owner path is untouched; this is consulted when ownership has
 *   already said no. A host cannot use it to take replay away from an owner.
 * - **It does not bypass the ability.** `manage-webhook-endpoints`, where a host defines it,
 *   still has to pass. This answers *whose endpoint*, never *may this person manage webhooks
 *   at all* — those are two questions and a host that tightened the second did not ask for the
 *   first to be loosened.
 * - **Replay implies readable, and that falls out of the order rather than being enforced
 *   twice.** The action loads the delivery row through the read-scoped query before it looks at
 *   the endpoint, so an endpoint declared replayable but not readable yields no row to replay.
 *   Stated here because the invariant is real and its mechanism is three files away.
 *
 * A set of ids rather than a predicate, for the same reason {@see ReadableEndpoints} gives: a
 * closure handed the query could drop the scoping entirely, and then the panel's central promise
 * would depend on host code. Here what the host adds is visible in one place.
 */
final class ReplayableEndpoints
{
    private static ?Closure $resolver = null;

    /**
     * Register the host's answer. The closure is called per resolution, never cached: a role can
     * be taken away mid-session, and somebody whose right to replay was withdrawn must not keep
     * it until they navigate.
     */
    public static function resolveUsing(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Drop the registered resolver — replay falls back to owner scoping alone.
     */
    public static function forget(): void
    {
        self::$resolver = null;
    }

    /**
     * The endpoint ids the acting reader may replay from beyond the ones they own.
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
     * Whether one endpoint is among them.
     */
    public static function allows(int $id): bool
    {
        return in_array($id, self::ids(), true);
    }

    /**
     * A resolver may return anything; only a list of ids is usable. A wrong shape is refused
     * loudly rather than coerced, because both plausible coercions are wrong and one of them is
     * wrong in the direction that grants: dropping unreadable entries would silently narrow, and
     * casting them would silently widen onto whatever id the cast produced.
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
                'A ReplayableEndpoints resolver must return a list of endpoint ids or null, got '
                .get_debug_type($resolved).'.'
            );
        }

        $ids = [];

        foreach ($resolved as $id) {
            if (! is_int($id)) {
                throw new RuntimeException(
                    'A ReplayableEndpoints resolver must return integer endpoint ids, got '
                    .get_debug_type($id).'.'
                );
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
