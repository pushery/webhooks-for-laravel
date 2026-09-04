<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Ssrf;

use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable;
use Pushery\Webhooks\Core\Http\Exceptions\NonRetryable;

/**
 * Vets an attacker-influenced webhook URL and returns a {@see PinnedEndpoint}
 * pinned to the exact vetted IP addresses. Run at registration time (Platform)
 * AND again immediately before each delivery attempt (Server) — resolving the
 * host itself each time is what defeats DNS rebinding. Fails closed.
 */
interface SsrfGuard
{
    /**
     * @throws BlockedDestination when the URL is malformed, uses a disallowed
     *                            scheme, is a blocked host, or resolves to any
     *                            private/reserved address. All of those are
     *                            {@see NonRetryable}: they are attacker influence
     *                            or misconfiguration, and the next attempt gets
     *                            the same answer.
     * @throws HostUnresolvable when the host resolved to no address. NOT
     *                          non-retryable, because PHP's resolver cannot say
     *                          whether the name is gone or the lookup merely
     *                          failed — see the exception for why the shipped
     *                          guard refuses to guess.
     */
    public function resolveAndPin(string $url): PinnedEndpoint;
}
