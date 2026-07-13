<?php

declare(strict_types=1);

namespace Webhooks\Core\Ssrf;

use Webhooks\Core\Http\Exceptions\BlockedDestination;

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
     *                            scheme, is a blocked host, is unresolvable, or
     *                            resolves to any private/reserved address
     */
    public function resolveAndPin(string $url): PinnedEndpoint;
}
