<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;

/**
 * Shapes how the self-service portal refuses a reader who lacks its ability.
 *
 * The gate itself is not negotiable and is not touched here: an unauthorized reader is
 * refused, before any panel renders and on every later interaction. What a host can choose is
 * what that refusal SAYS.
 *
 * 403 is the honest answer and stays the default: the surface exists, you may not have it.
 * For a host whose convention is to hide rather than to deny, it is also a disclosure — "real,
 * but not yours" confirms the application runs an endpoint portal at all, which rewards
 * guessing URLs. Those hosts answer 404 everywhere and want this surface to match, and until
 * now each of them wrote the same exception mapping in its own middleware.
 *
 * The precedent is `client.*.undetermined_status`, and so is the reasoning: a distinguishable
 * answer is information a prober can read too, which makes the choice the host's rather than
 * the package's. So the default changes nothing at all — at 403 the original
 * AuthorizationException is rethrown untouched, message and gate response included, and no
 * installation that leaves the key alone can tell this class exists.
 *
 * Not to be confused with row-level ownership, which is a separate and already-decided
 * question: a foreign endpoint id fails not-found BEFORE its policy, everywhere in this
 * package. That convention needs no configuration and is not affected by this one.
 *
 * @internal
 */
final class PortalRefusal
{
    /**
     * Run a portal ability check and shape its refusal.
     *
     * Takes the check as a callable rather than an ability name because the call sites are
     * Livewire components: `$this->authorize()` carries the component's own authorization
     * context, and reproducing it here would be a second, drifting implementation of it.
     *
     * The return type is `mixed`, not `void`: `authorize()` answers with an
     * `Illuminate\Auth\Access\Response`, and every call site here discards it, exactly as it
     * did before this seam existed.
     *
     * @param  callable(): mixed  $authorize
     */
    public static function shape(callable $authorize): void
    {
        try {
            $authorize();
        } catch (AuthorizationException $refused) {
            $status = Config::integer('webhooks.platform.self_service.refuse_with', 403);

            // Rethrown, not re-raised as an equivalent 403: the exception carries the gate's
            // own response and message, and a host's exception handler may well read them.
            if ($status === 403) {
                throw $refused;
            }

            abort($status);
        }
    }
}
