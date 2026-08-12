<?php

declare(strict_types=1);

namespace Webhooks\Livewire\Concerns;

use Illuminate\Support\Facades\Config;

/**
 * The per-action authorization seam of the operator console.
 *
 * The console stays what its docblocks say it is: unscoped, and guarded by a gate the host
 * puts in front of the page. This does not change that. It adds the assurance a page gate
 * cannot give, which is a different one rather than a stricter version of the same one.
 *
 * A page gate decides who receives a Livewire snapshot. Every interaction after that is a
 * separate request to Livewire's own endpoint, so a capability revoked DURING an open
 * session keeps working until the reader navigates — and a component embedded in a second
 * place inherits that page's gate rather than the original one. A check on each action
 * answers both, at the moment the action runs.
 *
 * Two ways in, and the default is neither: set webhooks.admin.ability to authorize every
 * action against one ability, or override authorizeAction() in a subclass for a rule no
 * ability can express. With the config left null this method does nothing at all, which is
 * exactly the behavior the console shipped with — a host that wants none of this notices
 * nothing.
 *
 * The consuming component is a Livewire component, so $this->authorize() comes from its
 * base class.
 *
 * @internal
 */
trait AuthorizesOperatorActions
{
    /**
     * Assert that the current user may take one operator action, named by the action.
     *
     * The name travels to the gate as its argument, so one ability can answer differently
     * for a delete than for a toggle without the host having to define five abilities. A
     * gate closure that ignores the extra argument is unaffected.
     */
    protected function authorizeAction(string $action): void
    {
        $ability = Config::get('webhooks.admin.ability');

        // An empty string is treated as unset rather than as an ability named '': a blank
        // env value must not silently become a gate nobody defined, which would deny every
        // action and read as the console being broken.
        if (! is_string($ability) || $ability === '') {
            return;
        }

        $this->authorize($ability, [$action]);
    }
}
