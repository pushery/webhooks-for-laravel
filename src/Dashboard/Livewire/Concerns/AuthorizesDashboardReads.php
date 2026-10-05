<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire\Concerns;

/**
 * Asks the dashboard's view gate on every request a panel serves.
 *
 * The panels are registered under an alias each so a host page can embed one, and an embedded
 * panel runs under the host page's middleware rather than the dashboard's. Livewire also
 * re-applies only persistent middleware on its own endpoint. So the gate the dashboard route
 * carries, `view-webhook-dashboard`, is asked here as well, the way the JSON metrics endpoint
 * asks it.
 *
 * It is asked from two hooks, because neither runs on every request. boot() is the first hook on
 * the mount and the hydrate path, so it refuses before mount() loads anything and before an
 * action runs. It does not run on the request that follows a `#[Lazy]` placeholder: Livewire
 * skips the hydrate of that snapshot, boot() included. That request is meant to be the
 * `__lazyLoad`, and nothing makes it so. A `$refresh`, a sort or a page change sent instead
 * renders the panel in full. The `rendering` hook runs before every render, which no placeholder
 * skips, and the render is where the panel's rows leave the server.
 *
 * An action sent to an unloaded placeholder still runs before that render. The one that changes
 * anything, redeliver(), asks the delivery policy itself; the others change what a table shows,
 * or dispatch a browser event that a refused render never delivers.
 *
 * The consuming component is a Livewire component, so $this->authorize() comes from its base
 * class.
 *
 * @internal
 */
trait AuthorizesDashboardReads
{
    public function bootAuthorizesDashboardReads(): void
    {
        $this->authorize('view-webhook-dashboard');
    }

    /**
     * The same gate before every render, because boot() does not run on the request that follows
     * a lazy placeholder (see above). Variadic because Livewire hands trait hooks their context as
     * named arguments, and this check needs none of it.
     */
    public function renderingAuthorizesDashboardReads(mixed ...$context): void
    {
        $this->authorize('view-webhook-dashboard');
    }
}
