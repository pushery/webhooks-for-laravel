<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Livewire\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

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
 * The ability must be one `Gate::define()` declared, not a permission name from a package that
 * resolves permissions through its own `Gate::before` hook. Such a hook conventionally reads the
 * first positional gate argument as a GUARD name and shifts it off the argument list:
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
 * NAME rather than passed beside it, which is how a permission package models capabilities in
 * the first place. The older single-ability key keeps its argument and its behavior untouched, so a
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
     * is unaffected. That argument is what makes such a permission name unusable on this
     * path — see the class docblock — and it is kept rather than removed because removing it
     * would take a documented capability away from every host that does use it. The map is
     * the way past it, not a replacement for it.
     */
    protected function authorizeAction(string $action): void
    {
        try {
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
        } catch (AuthorizationException $exception) {
            $this->refuseAction($exception);
        }
    }

    /**
     * How a refused action answers.
     *
     * 403 is what this console has always sent, and it stays the default: the original
     * exception is rethrown untouched, so a host that configured nothing sees the same type
     * it saw before rather than an equivalent one.
     *
     * A host whose admin area is deliberately unfindable needs 404 instead. There a 403 is a
     * disclosure — it confirms something exists at that address — and such a host wants every
     * surface answering alike rather than one imported console announcing itself. Set
     * webhooks.ui.refusal_status, or override this method for a rule a status cannot express.
     *
     * Anything outside the error range is ignored rather than honored. A refusal that answered
     * 200 would read as success to every caller, which is a far worse outcome than a setting
     * that quietly did nothing — and it is the kind of value that arrives from a mistyped
     * config rather than from a decision.
     */
    protected function refuseAction(AuthorizationException $exception): never
    {
        $status = $this->refusalStatus();

        // 403 leaves the refusal exactly as it was rather than rebuilding an equivalent one
        // through abort(). It is the default, so this is the path almost every host takes,
        // and on it the caller must keep seeing the same exception type it always saw.
        if ($status === 403) {
            throw $exception;
        }

        abort($status);
    }

    /**
     * The status a refused action answers with.
     *
     * Repeats the shipped default, because an absent key reads as null and a null here would
     * have to mean something -- and every meaning available is worse than the declared 403.
     * A host on a trimmed publish or a stale config cache keeps the behavior it had.
     * ConfigDefaultsAreInSyncTest holds this number against the shipped one.
     *
     * Anything outside the error range collapses to 403 rather than being honored. A refusal
     * that answered 200 would read as success to every caller, which is a far worse outcome
     * than an ignored setting -- and such a value arrives from a mistyped config, never from
     * a decision.
     */
    protected function refusalStatus(): int
    {
        $status = Config::get('webhooks.ui.refusal_status', 403);

        return is_int($status) && $status >= 400 && $status <= 599 ? $status : 403;
    }

    /**
     * Whether this action would be allowed, WITHOUT throwing.
     *
     * The twin of {@see self::authorizeAction()}, and it exists so a view can decide whether to
     * render a control rather than offering one that answers 403 on click. It is not a second
     * decision: it walks the same two config keys in the same order and returns true in the
     * same place the other one returns without authorizing, so a host that configures nothing
     * sees every control exactly as before.
     *
     * This is not a replacement for the check on the action, and nothing here weakens it.
     * Markup is a suggestion: the action still calls authorizeAction() and still refuses, which
     * is what protects an operator who kept a page open past a revoked capability. Hiding a
     * button a reader may not use is a courtesy; refusing the request is the control.
     *
     * A subclass that overrides authorizeAction() for a rule no ability can express should
     * override this too, or its controls will render for readers the action then refuses. The
     * default here cannot see such a rule, and guessing at one would be worse than saying so.
     */
    public function canAction(string $action): bool
    {
        $mapped = $this->mappedAbility($action);

        if ($mapped !== null) {
            return Gate::allows($mapped);
        }

        $ability = Config::get('webhooks.admin.ability');

        // The same reading as above: an empty string is unset, not an ability named '', so a
        // blank env value leaves the console exactly as it shipped instead of hiding every
        // control on every row.
        if (! is_string($ability) || $ability === '') {
            return true;
        }

        return Gate::allows($ability, [$action]);
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
