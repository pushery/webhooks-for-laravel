<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Core\Ssrf\SsrfGuard;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Livewire\Concerns\AuthorizesOperatorActions;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Support\UiVariant;

/**
 * The OPERATOR console for webhook endpoints: register one, edit it, switch it on or off,
 * rotate its signing secret, delete it. A published stub — restyle it and make it yours.
 *
 * It is deliberately UNSCOPED, and unauthorized by default: it lists and mutates EVERY
 * endpoint in the installation regardless of owner, and the endpoints it registers are
 * global (owner-less), so every tenant's events reach them. That is what an operator screen
 * is for — and it means the component MUST be embedded behind an operator-only gate of your
 * own. It is not a tenant-facing surface, and putting it on one leaks endpoints across
 * tenants.
 *
 * That gate stays yours and stays required. What each action adds is a check at the moment
 * it runs, which your page gate cannot give: name an ability per action in
 * webhooks.admin.abilities, set a single webhooks.admin.ability, or override
 * authorizeAction() in a subclass. Left unset it does nothing, so this component behaves
 * exactly as it always has. See {@see AuthorizesOperatorActions} for why the three differ.
 *
 * The map is the one a permission-based host needs, and it exists because the other two
 * were both barred there for a while. The single-ability key passes the action name to the
 * gate positionally, which spatie/laravel-permission's Gate::before hook takes for a guard
 * name and shifts away — every action then denies every operator, silently. The documented
 * escape was the subclass override, and until v2.0.1 this class was `final`, so it could
 * not be written: `cannot extend final class`. A host had exactly one screw to turn and it
 * was the broken one. An ability from the map is authorized with no argument at all, so
 * there is nothing left for that hook to mistake.
 *
 * Two of the actions are here because their absence cost something the console exists for:
 *
 * - **rotate is the emergency action.** A leaked signing secret has to be rollable from the
 *   surface that manages it; without it an operator is left with tinker or a database write
 *   at the moment speed matters most. The old secret keeps verifying for the rotation
 *   window, so rotating does not take the integration down with it.
 * - **edit is the everyday one.** Without it, correcting a URL or an event selection means
 *   delete-and-recreate — which is not the same operation. The endpoint gets a NEW identity,
 *   and its delivery history, its health state and its active secret go with the old one.
 *
 * The tenant-facing surface is the self-service portal
 * (`Pushery\Webhooks\Platform\Livewire\EndpointList`), which is owner-scoped and
 * policy-guarded on every action.
 */
class SubscriptionManager extends Component
{
    use AuthorizesOperatorActions;
    use WithPagination;

    /**
     * The endpoint the form is editing, or null while it is registering a new one.
     *
     * It decides which action name the save authorizes against and which row is written,
     * and it is re-resolved from the database on the request that writes — never trusted as
     * a carrier of the row's values.
     */
    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    /** @var array<int, string> */
    public array $eventTypes = [];

    public bool $isActive = true;

    /** The plaintext signing secret is shown once, right after creation or a rotation. */
    public ?string $newSecret = null;

    /**
     * Whether the secret on show came from a rotation rather than a registration. Only the
     * heading differs, and it differs for a reason: after a rotation the reader also has to
     * be told that the previous secret keeps verifying until the window closes.
     */
    public bool $rotated = false;

    /**
     * Drop the plaintext secret before the component is serialized.
     *
     * It has to reach the ONE response that reveals it — that is what it is for — and it has no
     * business in the component's state afterwards, where every later request of the session
     * would carry it again long after the operator copied it.
     *
     * The lifecycle is what makes this exact rather than approximate: Livewire renders first,
     * then triggers `dehydrate`, and only then takes the snapshot. So the value is in the HTML
     * and in **no** snapshot at all — including the one on that very response, which a
     * `hydrate()`-based retraction cannot manage, because by then the plaintext has already been
     * serialized and sent once.
     *
     * The alternative was a reveal window like the self-service panel's. That one can afford a
     * TTL because it also has `reveal()`: when the window closes, the tenant simply asks again.
     * This console has no way back, so a timer would only decide how long the operator has before
     * the secret is unrecoverable — and rotating again is a change every consumer of that
     * endpoint must follow.
     */
    public function dehydrate(): void
    {
        $this->newSecret = null;
        // This line cannot change what a reader sees: the line above nulls the secret, and the
        // panel this flag heads is only rendered when there is one, so after dehydrate() the flag's
        // value is unreachable. Set it to true and every suite stays green. The same is true of its
        // twin in edit(). It is kept so the two fields that describe one revealed secret are always
        // cleared together, rather than leaving a stale flag for whoever next sets newSecret
        // without thinking about it.
        $this->rotated = false;
    }

    /**
     * Register a new endpoint.
     *
     * Kept as its own entry point because published stubs call it by name; it is the save
     * with nothing opened for editing.
     */
    public function create(): void
    {
        $this->editingId = null;

        $this->save();
    }

    /**
     * Load one endpoint into the form.
     *
     * Authorized on opening as well as on saving. The save-time check is the load-bearing
     * one — it is the request that writes — but a form that opens for a reader who will be
     * refused at the end is a worse answer than one that never opens.
     */
    public function edit(int $id): void
    {
        $this->authorizeAction('edit');

        $subscription = WebhookSubscription::query()->findOrFail($id);

        $this->editingId = $subscription->id;
        $this->name = $subscription->name ?? '';
        $this->url = $subscription->url;
        // Normalized on the way IN, the same way save() is careful on the way out — a row
        // holding a JSON object or a number would otherwise open and never save again.
        // See EventTypeList.
        $this->eventTypes = $subscription->eventTypeNames();
        $this->isActive = $subscription->is_active;

        // A secret revealed for another endpoint has no business staying on screen over a
        // form that now describes a different one.
        //
        // The `rotated` line is equivalent for the same reason as the one in dehydrate(): the
        // secret goes with it and the panel needs the secret. Kept for the same reason too.
        $this->newSecret = null;
        $this->rotated = false;
        $this->resetValidation();
    }

    /**
     * Leave edit mode and return the form to registering a new endpoint.
     */
    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'url', 'eventTypes', 'isActive']);
        $this->resetValidation();
    }

    /**
     * Validate the form and either register a new endpoint or update the opened one.
     */
    public function save(): void
    {
        $this->authorizeAction($this->editingId === null ? 'create' : 'edit');

        $accepted = new Settings()->acceptedEventTypes();

        // What the opened row already holds stays acceptable even once the catalog stops
        // declaring it. The usual adoption order writes the catalog AFTER the endpoints
        // exist, and without this a rename would be refused over a value the operator never
        // touched — with no checkbox to remove it by, since the form draws one per catalog
        // type. Read from the ROW, never from component state: a public property is
        // writable from the browser, so an allowlist widened from one is an allowlist the
        // client widens.
        if ($accepted !== null && $this->storedEventTypes() !== []) {
            // array_unique and array_values on this line cannot change the outcome: the result only
            // ever reaches Rule::in, which cares about neither duplicates nor keys. Each was
            // removed in turn and the suite stayed green. The spread is a different matter and is
            // pinned — drop the stored half and an edit is refused over a value the operator never
            // touched, which is what makes this a note about the two helpers.
            $accepted = array_values(array_unique([...$accepted, ...$this->storedEventTypes()]));
        }

        // Four of these rule items are redundant against the property declarations, and each is
        // kept for the same reason. Removing one in turn leaves the whole Livewire suite green:
        //
        // 'name' => 'nullable' — $name is a typed string property; it is never null 'name' =>
        // 'string' — same, the type already guarantees it 'eventTypes' => 'array' — $eventTypes is
        // a typed array property 'eventTypes' => 'min:1' — masked by 'required', which already
        // rejects []
        //
        // The first three are the validator restating what the TYPE system enforces one layer
        // up, so no input can reach them; the fourth is redundant against its own neighbor.
        // 'required' and 'max:255' ARE reachable and are pinned — removing either goes red.
        //
        // They stay, and they are not to be "killed" by deletion. This list is the written
        // contract of the form, read by anyone changing it, and a subclass that widens a
        // property's type (this class is not final, deliberately) walks straight into the case
        // the type no longer covers.
        $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            // Cap the URL at the MySQL column width so it stores the same on every
            // supported engine (varchar(2048) there, unbounded text on Postgres).
            // `url:http,https`, not a bare `url`. Laravel's bare rule answers through Str::isUrl(),
            // which checks a built-in list of over 200 schemes: ftp, file, data and chrome all pass
            // it. The narrowing has been the supported spelling since 9.44.
            //
            // Without it a foreign scheme was refused only by the SSRF guard, which sits
            // AFTER the registration rate limiter has already spent a token, the lock has
            // been taken and the endpoint cap queried. So a typo cost the tenant part of
            // their registration budget and returned the generic "cannot be used as an
            // endpoint" instead of the field message that exists for exactly this. The
            // guard stays the authority; the rule only takes from it the cases that never
            // needed to reach it.
            'url' => ['required', 'url:http,https', 'max:2048'],
            'eventTypes' => ['required', 'array', 'min:1'],
            // Constrained to the catalog when the host keeps one, and unconstrained when it
            // does not — the catalog ships empty. An operator registers a GLOBAL endpoint
            // here, so a typo costs every tenant's events for that type, not one tenant's.
            'eventTypes.*' => $accepted === null ? ['string'] : ['string', Rule::in($accepted)],
        ], [
            // The 'string' rule had no message, so a non-string element rendered "The eventTypes.0
            // field must be a string.", the framework's English default, in a package that ships
            // seven locales, carrying a raw field path. Measured on both this console and the
            // portal form, which had the identical gap.
            'eventTypes.*.string' => __('webhooks::management.validation.event_types.string'),
            'eventTypes.*.in' => __('webhooks::management.validation.event_types.in'),
        ]);

        if ($this->editingId === null) {
            $this->register();

            return;
        }

        $this->update();
    }

    /**
     * Switch an endpoint on or off through the manager, so this console cannot drift from
     * what activation means anywhere else — most importantly, re-enabling clears the
     * circuit-breaker streak, without which the endpoint would disable itself again on
     * its next final failure.
     */
    public function toggle(int $id): void
    {
        $this->authorizeAction('toggle');

        $subscription = WebhookSubscription::query()->findOrFail($id);

        $subscription->is_active
            ? Webhooks::disable($subscription)
            : Webhooks::enable($subscription);
    }

    /**
     * Issue a new signing secret for an endpoint and show it once.
     *
     * The endpoint keeps the old secret as its verify-only rotation secret, so deliveries
     * signed with either verify until the rotation window closes. That is what makes this
     * usable in the incident it exists for: the leak is closed immediately and the receiver
     * is not knocked offline while it redeploys.
     */
    public function rotate(int $id): void
    {
        $this->authorizeAction('rotate');

        $subscription = WebhookSubscription::query()->findOrFail($id);

        $this->newSecret = Webhooks::rotateSecret($subscription);
        $this->rotated = true;
    }

    /**
     * Permanently remove an endpoint (and, by FK cascade, its delivery log). To stop
     * delivering while keeping the history, toggle it off instead.
     *
     * NOT named `delete`, and the reason is not style. Livewire's CSP-safe build parses a
     * `wire:click` expression itself rather than handing it to the JS engine, and `delete`
     * is a KEYWORD in that parser — `wire:click="delete(1)"` reads as the delete OPERATOR,
     * so the button silently does nothing. No error, no log, and an operator who clicks it
     * concludes the endpoint is gone. CspSafeMethodNameTest holds the whole class.
     */
    public function destroy(int $id): void
    {
        $this->authorizeAction('delete');

        Webhooks::unsubscribe(WebhookSubscription::query()->findOrFail($id));

        // The list and the form share one screen, so deleting the row that is open for
        // editing is an ordinary thing to do. Leaving the id standing would make every
        // later render re-resolve a row that no longer exists — the console would answer
        // 404 for the rest of the session over a deletion the operator just performed.
        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    /**
     * The pre-2.0.0 name, kept so a view published before the rename keeps working. Under a
     * strict CSP that published copy is ALREADY broken — `delete` is a keyword in Livewire's
     * own expression parser, so `wire:click="delete(1)"` parses as the delete OPERATOR rather
     * than a call. Re-publish the view, or change that one line, to get the button back.
     *
     * Deliberately NOT tagged `@deprecated`, and that is not an oversight. On PHP 8.4 the
     * code-style pass rewrites that tag into `#[\Deprecated]`, which raises E_USER_DEPRECATED
     * on every call — and a host that runs PHPUnit with `failOnDeprecation` would then have
     * this shim break the very tests it exists to keep working. A compatibility forwarder
     * that fails the people who have not migrated yet is worse than no forwarder.
     */
    public function delete(int $id): void
    {
        $this->destroy($id);
    }

    private function register(): void
    {
        try {
            $subscription = Webhooks::subscribe(null, $this->url, array_values($this->eventTypes), $this->name ?: null);
        } catch (BlockedDestination) {
            // The guard's own message stays out of the form: it is an operator
            // diagnostic for the log, and it would tell a stranger which hosts resolve
            // where. The reader gets a translated sentence they can act on.
            $this->addError('url', __('webhooks::management.validation.url.blocked'));

            return;
        }

        // No activation branch here on purpose: a registration is active by definition, the
        // create form offers no switch, and create() has to keep behaving exactly as it
        // always did for the published stubs that still call it. A branch only a forged
        // payload could enter is a branch no reader can reason about.
        $this->newSecret = $subscription->secret;
        $this->rotated = false;
        $this->reset(['name', 'url', 'eventTypes', 'isActive']);
    }

    private function update(): void
    {
        // The (int) cast cannot fail: editingId is a typed ?int and the null case cannot reach
        // here, because update() is only called with one open. It is kept as the boundary that
        // makes the argument an int rather than a nullable one.
        $subscription = WebhookSubscription::query()->findOrFail((int) $this->editingId);

        try {
            // Re-vet the (possibly changed) destination before repointing the endpoint.
            // Without this an edit would be the way around the guard that vets a
            // registration: register a public URL, then quietly move the row to an
            // internal one.
            Container::getInstance()->make(SsrfGuard::class)->resolveAndPin($this->url);
        } catch (BlockedDestination) {
            $this->addError('url', __('webhooks::management.validation.url.blocked'));

            return;
        }

        $subscription->name = $this->name !== '' ? $this->name : null;
        $subscription->url = $this->url;
        $subscription->event_types = array_values($this->eventTypes);
        $subscription->save();

        // The activation flag goes through the manager, never through this form's own
        // assignment: switching an endpoint back on has to clear the circuit-breaker
        // streak too, or the next final failure disables it again immediately. An
        // unchanged flag is left alone, so re-saving a disabled endpoint keeps the
        // disabled_at stamp it already carries.
        if ($this->isActive !== $subscription->is_active) {
            $this->isActive
                ? Webhooks::enable($subscription)
                : Webhooks::disable($subscription);
        }

        $this->cancel();
    }

    /**
     * What the endpoint currently open for editing already holds, read from the ROW.
     *
     * A row that is gone answers with nothing rather than a 404: this is consulted on every
     * render, so an endpoint deleted from a second tab would otherwise take the whole
     * console down for the rest of the session. The save path re-resolves it strictly.
     *
     * @return list<string>
     */
    #[Computed]
    private function storedEventTypes(): array
    {
        if ($this->editingId === null) {
            return [];
        }

        $subscription = WebhookSubscription::query()->find($this->editingId);

        if ($subscription instanceof WebhookSubscription) {
            // Through the accessor, not the raw column: what the row holds is spread into
            // the validation allowlist, and a nested array there reaches Rule::in, which
            // stringifies it — an "Array to string conversion" warning raised while
            // validating, on a screen that exists to repair exactly such a row.
            return $subscription->eventTypeNames();
        }

        return [];
    }

    /**
     * Page the list with the package's own pagination control rather than Livewire's
     * built-in one, whose markup paints a raw color palette no design token reaches and
     * whose landmark carries a hardcoded English accessible name. Publishing the views
     * (webhooks-views) publishes this control alongside them, so a host on another design
     * system restyles it in place. Same control, same reasoning as the delivery log beside it.
     */
    public function paginationView(): string
    {
        return 'webhooks::pagination';
    }

    /**
     * And the same control for the SIMPLE paginator this component actually builds.
     *
     * Livewire resolves the two independently: paginationView() feeds
     * Paginator::defaultView, paginationSimpleView() feeds Paginator::defaultSimpleView,
     * and a simple paginator reads only the second. Overriding just the first left this
     * screen on livewire::simple-tailwind -- Livewire's own markup, with a hardcoded
     * English landmark and the raw palette the docblock above says is deliberately not
     * used. The package view carries both types.
     */
    public function paginationSimpleView(): string
    {
        return 'webhooks::pagination';
    }

    public function render(): View
    {
        return ViewFactory::make(UiVariant::view('subscription-manager'), [
            // simplePaginate, not paginate, and not a bare get(): this stub is unscoped over
            // the whole installation, so the list grows with every endpoint anyone ever
            // registered. A bare get() hydrates all of them into memory to render one screen.
            // simplePaginate asks for one page and one row beyond it — no count(*) over a
            // table whose size is the thing being complained about. Same reasoning, same
            // page size as the delivery log beside it.
            //
            // The paginator is only half of paging, and the missing half is silent. Without
            // WithPagination above, the control below still renders and still takes clicks, it just
            // never moves. The paginator reads its page from the request via
            // Paginator::resolveCurrentPage(), and a Livewire update request carries no `page`
            // parameter, so every answer is page one. The failure has no error and no log line: the
            // list simply looks like an installation with 25 endpoints in it, which on the one
            // screen whose whole point is that the list outgrows a screen is the worst possible way
            // to be wrong.
            //
            // `latest()` alone is not a total order. created_at has second resolution, and a
            // paginated read is several queries: where two rows tie, the database may order them
            // differently per query, so a reader sees one endpoint twice and another never, on the
            // same screen whose whole point is that the list outgrows a page.
            'subscriptions' => WebhookSubscription::query()->latest()->orderByDesc('id')->simplePaginate(25),
            // The catalog, plus anything the OPENED ROW already holds that the catalog no
            // longer declares. Without the second half the stale value has no checkbox, so
            // it can be neither kept nor dropped — Livewire's checkbox binding only ever
            // adds or removes its OWN value.
            // The array_values here cannot change the outcome (the view iterates, keys unread)
            // while its neighbor array_unique can — remove that one and an arm goes red. Both
            // stay.
            'availableEventTypes' => array_values(array_unique([
                ...new Settings()->eventTypes(),
                ...$this->storedEventTypes(),
            ])),
        ]);
    }
}
