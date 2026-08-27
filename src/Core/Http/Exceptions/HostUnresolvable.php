<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http\Exceptions;

use RuntimeException;

/**
 * The webhook host resolved to no address, and this package cannot tell you why.
 *
 * ⚠️ IT IS DELIBERATELY *NOT* {@see NonRetryable}, and that is the whole reason it exists
 * beside {@see BlockedDestination}.
 *
 * PHP's resolver does not distinguish a name that does not exist from a lookup that failed:
 * `gethostbynamel()` answers `false` for NXDOMAIN, for SERVFAIL, for a resolver timeout and
 * for a few seconds of lost network alike, and `dns_get_record()` answers `false` the same
 * way. Only one of those four is permanent.
 *
 * Treated as final, the three transient ones cost a delivery each: one attempt, given up,
 * while the retry budget stood untouched — and each counted as a consecutive final failure,
 * so a short DNS wobble could walk a perfectly healthy endpoint into the circuit breaker's
 * threshold and switch it off. Treated as retryable, a genuinely dead host costs a handful
 * of cheap lookups before the budget runs out and the delivery ends `exhausted` anyway.
 * The asymmetry is not close.
 *
 * {@see BlockedDestination::unresolvable()} stays for a guard implementation that CAN make
 * the distinction — a resolver reading a real NXDOMAIN may still refuse finally, and should.
 * The shipped guard cannot, so it does not claim to.
 */
final class HostUnresolvable extends RuntimeException
{
    public static function for(string $host): self
    {
        return new self("The webhook host [{$host}] could not be resolved to any IP address.");
    }
}
