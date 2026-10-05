<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Support;

use Closure;
use Pushery\Webhooks\Support\TenantIdentity;
use RuntimeException;

/**
 * The host's own answer to "may this tenant register one more endpoint?".
 *
 * The portal limits registration with `webhooks.platform.self_service.max_endpoints_per_tenant`,
 * a number of endpoints. A host whose allowance counts more than endpoints cannot say that with a
 * number: one consuming application gives each account ten destinations across chat services and
 * webhooks together, and the portal could only count the webhooks among them. Refusing afterwards,
 * from a listener on the registration event, is no answer either: the tenant has just been told
 * the endpoint was created.
 *
 * So the host registers a resolver, from a service provider like the tenant resolver beside it:
 *
 *     RegistrationLimit::resolveUsing(
 *         fn (TenantIdentity $tenant): bool|string => Destinations::of($tenant)->count() < 10
 *             ?: 'You have used all ten of your destinations.',
 *     );
 *
 * It returns true to allow the registration, false to refuse it with the portal's own sentence,
 * or a sentence of its own to refuse it with that. The portal asks it inside the tenant's
 * registration lock, together with the configured cap, so the answer and the insert cannot be
 * separated by a concurrent registration of the same tenant.
 *
 * It can only refuse. The configured cap still applies when one is set, so a resolver cannot lift
 * a tenant past it; leave the cap unset to let the resolver decide alone. With no resolver
 * registered nothing changes.
 */
final class RegistrationLimit
{
    private static ?Closure $resolver = null;

    /**
     * Register the host's answer. The closure is called per check, never cached: an allowance
     * changes as the tenant adds and removes what it counts.
     *
     * @param  Closure(TenantIdentity): (bool|string)  $resolver
     */
    public static function resolveUsing(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Drop the registered resolver; the configured cap is the only limit again.
     */
    public static function forget(): void
    {
        self::$resolver = null;
    }

    /**
     * Whether a host registered a resolver, so a registration has something to ask beyond the cap.
     */
    public static function isResolved(): bool
    {
        return self::$resolver instanceof Closure;
    }

    /**
     * The sentence a refused registration shows, or null when the host's resolver allows it or no
     * resolver is registered. False from the resolver is the portal's own sentence.
     */
    public static function refusal(TenantIdentity $tenant, string $default): ?string
    {
        if (! self::$resolver instanceof Closure) {
            return null;
        }

        $verdict = (self::$resolver)($tenant);

        if ($verdict === true) {
            return null;
        }

        if ($verdict === false) {
            return $default;
        }

        // A wrong shape is refused loudly rather than read as either answer: read as an
        // allowance it would lift the limit the host meant to set, read as a refusal it would
        // lock every tenant out over a typo.
        if (! is_string($verdict) || trim($verdict) === '') {
            throw new RuntimeException(
                'A RegistrationLimit resolver must return true, false or the sentence a refused '
                .'registration shows, got '.(is_string($verdict) ? 'an empty string' : get_debug_type($verdict)).'.'
            );
        }

        return trim($verdict);
    }
}
