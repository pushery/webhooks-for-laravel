<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Support\PerMinuteBrake;
use Pushery\Webhooks\Support\TenantIdentity;

/**
 * The three limits one tenant's registrations are held to: the cap on how many endpoints it ends up with, the lock
 * that keeps the cap and the insert together, and the brake on how fast it registers. The portal's panels ask them for
 * the tenant in scope, and {@see SelfServiceEndpoints} for the tenant a host names, so a registration from a host's
 * own API is held to exactly the portal's limits.
 *
 * @internal
 */
final class EndpointRegistration
{
    /**
     * How long the registration lock is held before it expires on its own, in seconds.
     *
     * A backstop, not a budget: the section it guards is one count and one insert. It
     * exists so a request killed mid-registration cannot wedge a tenant out of registering
     * until the cache is flushed.
     */
    private const int LOCK_TTL = 10;

    /**
     * How long a registration waits for a concurrent one to finish, in seconds.
     *
     * Waiting is the right answer rather than refusing outright: the other request
     * finishes in milliseconds, and the waiter then re-reads the cap and gets a correct
     * verdict — created, or honestly at the limit. Only a wait past this is reported as
     * contention.
     */
    private const int LOCK_WAIT = 3;

    /**
     * The window the registration allowance is measured over, in seconds.
     */
    private const int RATE_WINDOW = 60;

    /**
     * How many endpoints a single tenant may register, or null for unlimited.
     */
    public static function maxEndpoints(): ?int
    {
        $max = Config::get('webhooks.platform.self_service.max_endpoints_per_tenant');

        if (is_int($max) && $max >= 0) {
            return $max;
        }

        return null;
    }

    /**
     * The sentence a registration of this tenant is refused with right now, or null when it may register one more
     * endpoint. The configured cap answers first, in the portal's own words, which the caller passes for where it is
     * shown; then the host's own limit, {@see RegistrationLimit}, in its words or in the portal's. An unset cap with
     * no host limit is always null.
     *
     * Read on its own this is only ever advisory — it is what decides whether a button is drawn. The decision that
     * must hold is the one asked inside {@see self::underLock()}, where the answer is pinned.
     */
    public static function refusal(TenantIdentity $owner, string $portalSentence): ?string
    {
        $max = self::maxEndpoints();

        if ($max !== null && SubscriptionScope::forOwner($owner->type, $owner->id)->count() >= $max) {
            return $portalSentence;
        }

        return RegistrationLimit::refusal($owner, $portalSentence);
    }

    /**
     * Run a registration inside this tenant's registration lock, so the cap check and the insert cannot interleave
     * with a concurrent registration.
     *
     * Without it the cap is read-then-act: two requests both read count = max - 1, both pass, both insert, and the cap
     * is over with nothing to notice. That is not a contrived race — the cap bounds a self-service resource, and a
     * double-submit is the ordinary way one customer produces two simultaneous registrations.
     *
     * Keyed by the whole morph pair, matching the query scope exactly, so two tenants never wait on each other and two
     * tenants sharing an owner_id under different owner types are still separate. With no cap configured and no host
     * limit registered there is nothing to race for and no lock is taken, so an unlimited installation pays nothing for
     * this. A host limit races exactly like the cap: its answer and the insert belong together.
     *
     * The lock comes from the default cache store, and three of Laravel's stores cannot give one (`session`, `apc`,
     * `storage`). There the registration runs without it: the cap still holds for registrations that arrive one after
     * another, and two that arrive at the same moment can both pass it. Asking such a store for a lock would end every
     * registration in an error.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $register
     * @return TValue
     *
     * @throws LockTimeoutException when a concurrent registration held the lock too long
     */
    public static function underLock(TenantIdentity $owner, Closure $register): mixed
    {
        if (self::maxEndpoints() === null && ! RegistrationLimit::isResolved()) {
            return $register();
        }

        if (! Cache::getStore() instanceof LockProvider) {
            return $register();
        }

        return Cache::lock(self::lockKey($owner), self::LOCK_TTL)->block(self::LOCK_WAIT, $register);
    }

    /**
     * How many endpoints one tenant may register per minute, or null for no brake.
     *
     * A non-positive value reads as no brake rather than as "none allowed": a limit of zero would refuse every
     * registration, which is a way to disable the portal by typo rather than a setting anyone wants. A digit string,
     * as env() returns it, is the number it spells ({@see PerMinuteBrake}).
     *
     * The shipped default is repeated here for the same reason as the test-ping brake: an absent key reads as null and
     * switches the brake off, and a host on a config cache built before this version upgraded still has the old
     * trimmed layer until it rebuilds. It matches the shipped default in config/webhooks.php.
     */
    public static function maxPerMinute(): ?int
    {
        return PerMinuteBrake::read(Config::get('webhooks.platform.self_service.registrations_per_minute', 10), 10);
    }

    /**
     * Whether this tenant has spent its registration allowance for the current minute, spending one when it has not.
     *
     * The cap and this answer different questions: the cap bounds how many endpoints a tenant ends up with, this
     * bounds how fast it gets there. An installation with no cap has no answer to a client that registers in a loop,
     * and one with a cap still has none to a client that empties and refills it.
     *
     * The bucket is spent by attempts that reach the write path — validation and authorization have already run, so a
     * rejected form costs nothing against it.
     */
    public static function rateExceeded(TenantIdentity $owner): bool
    {
        $max = self::maxPerMinute();

        if ($max === null) {
            return false;
        }

        return PerMinuteBrake::spend([self::rateKey($owner)], $max, self::RATE_WINDOW) !== null;
    }

    /**
     * The registration allowance's cache key for one tenant. Distinct from the registration lock's key: one bounds how
     * fast a tenant may register, the other keeps a single registration atomic, and sharing a key would make each
     * break the other.
     */
    public static function rateKey(TenantIdentity $owner): string
    {
        return 'webhooks:endpoint-registration-rate:'.str_replace('\\', '.', $owner->type).':'.$owner->id;
    }

    /**
     * The registration lock's cache key for one tenant. The morph class is dotted rather than hashed so the key stays
     * legible in a cache browser; both halves are present, so it identifies exactly the tenant the scoped query does.
     */
    public static function lockKey(TenantIdentity $owner): string
    {
        return 'webhooks:endpoint-registration:'.str_replace('\\', '.', $owner->type).':'.$owner->id;
    }
}
