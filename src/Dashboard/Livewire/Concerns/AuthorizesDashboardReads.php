<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire\Concerns;

/**
 * Asks the dashboard's view gate on every request a panel serves.
 *
 * The panels are registered under an alias each so a host page can embed one, and an embedded
 * panel runs under the host page's middleware rather than the dashboard's. Livewire also
 * re-applies only persistent middleware on its own endpoint. So the gate the dashboard route
 * carries, `view-webhook-dashboard`, is asked here as well, on the first render and on every
 * update after it, the way the JSON metrics endpoint asks it.
 *
 * A lazy panel's placeholder renders before this runs; the panel's content does not.
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
}
