<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The replay allowance one tenant spends on every tenant-facing surface that can replay a
 * delivery: the self-service portal and the per-tenant dashboard.
 *
 * Replaying makes the server issue an HTTP request to a URL the tenant registered, so without a
 * brake one customer holding the button down is an amplifier pointed wherever they like. The
 * SSRF guard decides where a request may go; this decides how many. Both surfaces spend one
 * budget under one key, because a brake one surface keeps and the other skips is no brake. The
 * operator console and the operator views of the dashboard act on no single tenant's behalf and
 * spend nothing.
 *
 * @internal
 */
final class ReplayAllowance
{
    /**
     * The window the allowance is measured over, in seconds.
     */
    private const int WINDOW = 60;

    /**
     * How many deliveries one tenant may replay per minute, or null for no brake.
     *
     * A non-positive value reads as no brake rather than as "none allowed": a limit of zero
     * would refuse every replay, which is a way to disable a feature by typo rather than a
     * setting anyone wants. A digit string, as env() returns it, is the number it spells
     * ({@see PerMinuteBrake}). The shipped default is repeated here because an absent key reads
     * as null, and a host on a config cache built before this setting existed still has a layer
     * without it.
     */
    public static function perMinute(): ?int
    {
        return PerMinuteBrake::read(Config::get('webhooks.platform.self_service.replays_per_minute', 10), 10);
    }

    /**
     * Spend one replay for the tenant. False when the allowance for the current minute is
     * already spent, in which case nothing is taken from it.
     */
    public static function spend(TenantIdentity $owner): bool
    {
        $max = self::perMinute();

        if ($max === null) {
            return true;
        }

        $key = self::key($owner);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return false;
        }

        RateLimiter::hit($key, self::WINDOW);

        return true;
    }

    /**
     * The cache key of one tenant's allowance. The morph type's backslashes become dots: a
     * backslash is a character some cache drivers mangle, and the next request would then read
     * a different key than this one wrote.
     */
    public static function key(TenantIdentity $owner): string
    {
        return 'webhooks:delivery-replay-rate:'.str_replace('\\', '.', $owner->type).':'.$owner->id;
    }
}
