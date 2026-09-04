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
 * Three ways in, and the default is none of them: set webhooks.admin.abilities to name an
 * ability PER ACTION (or one for all of them under '*'), set webhooks.admin.ability to
 * authorize every action against a single ability with the action name as its argument, or
 * override authorizeAction() in a subclass for a rule no ability can express. With both
 * config keys left at their defaults this method does nothing at all, which is exactly the
 * behavior the console shipped with — a host that wants none of this notices nothing.
 *
 * The ability must be one `Gate::define()` declared, not a spatie/laravel-permission permission
 * name. That package installs a `Gate::before` hook which reads the first positional gate argument
 * as a guard name and shifts it off the argument list:
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
 * The way out is webhooks.admin.abilities, and it exists because of this. An ability taken from
 * that map is authorized with no positional argument at all, so nothing travels in the slot the
 * hook reads as a guard and a permission name works as itself:
 *
 *     'abilities' => ['*' => 'manage webhooks'],            // one permission, every action
 *     'abilities' => ['delete' => 'delete webhooks', …],    // or one per action
 *
 * That is also the shape a permission-based host wants anyway: the action is encoded in the
 * NAME rather than passed beside it, which is how spatie models capabilities in the first
 * place. The older single-ability key keeps its argument and its behavior untouched, so a
 * host already relying on `fn ($user, string $action) => …` sees no change.
 *
 * The one-line workaround for a host that would rather not touch the config shape remains
 * available: declare an ability of your own that asks the permission internally, and point
 * webhooks.admin.ability at THAT. A closure declared with `fn ($user) => …` ignores an
 * argument it does not accept, which makes the action name harmless again.
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
     * The map wins when it names this action, and an ability that comes from it is
     * authorized ALONE — the action is already in the name, so no argument is passed and
     * there is nothing for a Gate::before hook to mistake for a guard.
     *
     * Otherwise the single-ability key answers, and there the name still travels to the gate
     * as its argument: one ability can answer differently for a delete than for a toggle
     * without the host having to define five. A gate closure that ignores the extra argument
     * is unaffected. That argument is what makes a spatie permission name unusable on this
     * path — see the class docblock — and it is kept rather than removed because removing it
     * would take a documented capability away from every host that does use it. The map is
     * the way past it, not a replacement for it.
     */
    protected function authorizeAction(string $action): void
    {
        $mapped = $this->mappedAbility($action);

        if ($mapped !== null) {
            $this->authorize($mapped);

            return;
        }

        $ability = Config::get('webhooks.admin.ability');

        // An empty string is treated as unset rather than as an ability named '': a blank
        // env value must not silently become a gate nobody defined, which would deny every
        // action and read as the console being broken.
        if (! is_string($ability) || $ability === '') {
            return;
        }

        $this->authorize($ability, [$action]);
    }

    /**
     * The ability this action is named against, or null when the map does not answer for it.
     *
     * '*' is the catch-all, and it is the entry most hosts will use on its own: one
     * permission for the whole console. The exact-action entry wins over it, so a host can
     * hold everything at one capability and lift `delete` to a stricter one without
     * enumerating the rest. The same precedence the client's `process` map uses, on purpose.
     *
     * A non-string or empty entry falls through to the single-ability key rather than
     * denying: a half-written map must not turn into a console that refuses everything,
     * which is the failure mode this whole seam exists to end.
     */
    private function mappedAbility(string $action): ?string
    {
        $map = Config::get('webhooks.admin.abilities');

        if (! is_array($map)) {
            return null;
        }

        $ability = $map[$action] ?? $map['*'] ?? null;

        if (! is_string($ability) || $ability === '') {
            return null;
        }

        return $ability;
    }
}
