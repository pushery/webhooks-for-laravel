<?php

declare(strict_types=1);

namespace Webhooks\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Webhooks\Core\Http\Exceptions\BlockedDestination;
use Webhooks\Core\Ssrf\SsrfGuard;
use Webhooks\Facades\Webhooks;
use Webhooks\Livewire\Concerns\AuthorizesOperatorActions;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\Settings;

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
 * it runs, which your page gate cannot give: set webhooks.admin.ability, or override
 * authorizeAction() in a subclass. Left unset it does nothing, so this component behaves
 * exactly as it always has. See {@see AuthorizesOperatorActions} for why the two differ.
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
 * (`Webhooks\Platform\Livewire\EndpointList`), which is owner-scoped and
 * policy-guarded on every action.
 */
final class SubscriptionManager extends Component
{
    use AuthorizesOperatorActions;

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
        $this->eventTypes = $subscription->event_types;
        $this->isActive = $subscription->is_active;

        // A secret revealed for another endpoint has no business staying on screen over a
        // form that now describes a different one.
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
            $accepted = array_values(array_unique([...$accepted, ...$this->storedEventTypes()]));
        }

        $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            // Cap the URL at the MySQL column width so it stores the same on every
            // supported engine (varchar(2048) there, unbounded text on Postgres).
            'url' => ['required', 'url', 'max:2048'],
            'eventTypes' => ['required', 'array', 'min:1'],
            // Constrained to the catalog when the host keeps one, and unconstrained when it
            // does not — the catalog ships empty. An operator registers a GLOBAL endpoint
            // here, so a typo costs every tenant's events for that type, not one tenant's.
            'eventTypes.*' => $accepted === null ? ['string'] : ['string', Rule::in($accepted)],
        ], [
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
     */
    public function delete(int $id): void
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

        return $subscription instanceof WebhookSubscription ? array_values($subscription->event_types) : [];
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::livewire.subscription-manager', [
            'subscriptions' => WebhookSubscription::query()->latest()->get(),
            // The catalog, plus anything the OPENED ROW already holds that the catalog no
            // longer declares. Without the second half the stale value has no checkbox, so
            // it can be neither kept nor dropped — Livewire's checkbox binding only ever
            // adds or removes its OWN value.
            'availableEventTypes' => array_values(array_unique([
                ...new Settings()->eventTypes(),
                ...$this->storedEventTypes(),
            ])),
        ]);
    }
}
