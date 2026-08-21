<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Livewire\Concerns;

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
 * ⚠️ THE ABILITY MUST BE ONE `Gate::define()` DECLARED — NOT A spatie/laravel-permission
 * PERMISSION NAME. That package installs a `Gate::before` hook which reads the FIRST
 * positional gate argument as a GUARD name and shifts it off the argument list:
 *
 *     if (is_string($args[0] ?? null) && ! class_exists($args[0])) {
 *         $guard = array_shift($args);
 *     }
 *
 * This seam passes the action name in exactly that position, so on such a host `'create'`
 * becomes the guard, the permission lookup asks for a guard nobody defined, the hook
 * declines to decide, and the check falls through to an ability that does not exist —
 * a DENY. Every action then refuses every operator, including the one the permission was
 * granted to, and it refuses SILENTLY: nothing throws and nothing is logged, so the form
 * simply does nothing when submitted.
 *
 * That failure is invisible to the obvious tests, and this is the part worth remembering:
 * a surface that denies everything looks exactly like a surface that is well guarded. Only
 * a POSITIVE arm — one asserting that a permitted operator really CAN act — can tell the
 * two apart, and that is the arm people rarely write.
 *
 * The way through on such a host is one line: declare an ability of your own that asks the
 * permission internally, and point webhooks.admin.ability at THAT. A closure declared with
 * `fn ($user) => …` ignores an argument it does not accept, which makes the action name
 * harmless again.
 *
 * The consuming components are deliberately NOT final, so the override this docblock offers
 * is actually reachable. It used to be advertised on two final classes, which made the only
 * documented escape from the trap above impossible to take.
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
     *
     * ⚠️ That argument is also what makes a spatie/laravel-permission NAME unusable here —
     * see the class docblock. The argument is kept because removing it would take a
     * documented capability away from every host that does use it; the incompatibility is
     * named instead, at the config key and here.
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
