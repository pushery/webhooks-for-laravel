<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\Platform\Support\SubscriptionScope;
use Pushery\Webhooks\Support\Settings;

/**
 * Create or edit a single endpoint. Opened by the list via the new-endpoint /
 * edit-endpoint events; a save either registers a fresh endpoint (generating and
 * storing its signing secret through the manager's existing scheme, then revealing it
 * once) or updates an owned one. The URL is SSRF-vetted before it is stored on both
 * paths, so a tenant can never register or repoint an endpoint at an internal address.
 *
 * Every mutation re-authorizes: create against the manage ability, edit against the
 * row-level policy for the owned endpoint.
 */
final class EndpointForm extends Component
{
    use InteractsWithEndpoints;

    public bool $open = false;

    public ?int $endpointId = null;

    public string $name = '';

    public string $url = '';

    /** @var array<int, string> */
    public array $eventTypes = [];

    public bool $isActive = true;

    /**
     * Open the form to register a new endpoint. Refused when the tenant is at its cap.
     */
    #[On('new-endpoint')]
    public function openForCreate(): void
    {
        $this->authorize('create', WebhookSubscription::class);
        $this->resetForm();

        if ($this->endpointCapReached()) {
            $this->dispatch('wirekit-toast', variant: 'warning', message: __('webhooks::self-service.limit_reached'));

            return;
        }

        $this->open = true;
    }

    /**
     * Open the form to edit one owned endpoint, pre-filled from its stored values.
     */
    #[On('edit-endpoint')]
    public function openForEdit(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        // This authorize() call cannot be the sole refusal: the boot gate reads the same ability,
        // and findOwnedEndpoint() has already enforced the ownership this policy would add.
        // InteractsWithEndpoints states that in full and ends "Do not 'kill' them by deleting
        // them"; this is one of the five it means. The (int) cast beside it is in the same position
        // — the value is already an int, and the cast is the boundary that makes the argument one.
        // Redundant with the boot gate, and deliberately kept: {@see InteractsWithEndpoints}
        // states the rule and its measurement -- the gate reads the same ability this policy
        // consults, and the ownership it adds is already enforced by the scoped lookup. This is
        // what still refuses if a future caller reaches the action without that lookup.
        $this->authorize('update', $subscription);

        $this->endpointId = $subscription->id;
        $this->name = $subscription->name ?? '';
        $this->url = $subscription->url;
        // Normalized on the way IN — see EventTypeList. The tenant-facing form has the same
        // exposure as the operator console, and a tenant has even less recourse.
        $this->eventTypes = $subscription->eventTypeNames();
        $this->isActive = $subscription->is_active;
        $this->open = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->open = false;
    }

    /**
     * Validate and persist. A new endpoint is registered through the manager; an
     * existing one is updated in place. A blocked destination is surfaced as a URL
     * error rather than an exception, so the form re-renders with the message.
     *
     * The messages and attribute names come from the package's own translations rather
     * than the framework's default lines, so a refused save speaks the reader's language
     * on a host that never translated Laravel's validation file.
     */
    public function save(): void
    {
        $accepted = new Settings()->acceptedEventTypes();

        if ($accepted !== null && $this->storedEventTypes() !== []) {
            // Neither call on this line can change the outcome. The result is only ever handed to
            // Rule::in, which cares about neither duplicates nor keys, so array_unique and
            // array_values are tidiness rather than behavior. Removing each in turn leaves the
            // portal suite green both times.
            //
            // They stay because the value reads as a list everywhere it is passed on, and a
            // duplicated or gap-keyed one would be a surprise waiting for whoever next uses it for
            // something that does care.
            $accepted = array_values(array_unique([...$accepted, ...$this->storedEventTypes()]));
        }

        $this->validate(
            // Four items in this list are redundant against the property declarations, each
            // measured by removing the item and running the portal suite:
            //
            // 'name' => 'nullable' $name is a typed string property; never null 'name' => 'string'
            // same, the type already enforces it 'eventTypes' => 'array' $eventTypes is a typed
            // array property 'eventTypes' => 'min:1' masked by 'required', which already rejects []
            //
            // Three restate what the type system enforces one layer up, so no input reaches them;
            // the fourth is redundant against its neighbor. They stay: this list is the form's
            // written contract, and a subclass widening a property's type walks straight into the
            // case the type stops covering.
            //
            // 'required', 'url', 'max:2048' and 'max:255' are reachable and every one of them goes
            // red when removed, which is what makes the four above a statement about those four
            // rather than about an untested validate() call.
            [
                'name' => ['nullable', 'string', 'max:255'],
                // Bound the URL length so it stores identically on every supported
                // engine: the column is varchar(2048) on MySQL (a hard 1406 error past
                // it) but unbounded text on Postgres, so without this cap the same long
                // URL would 500 on one engine and store on the other. Surfaced as a
                // field error the tenant can act on instead.
                // `url:http,https`, not a bare `url`. Laravel's bare rule answers through
                // Str::isUrl(), which checks a built-in list of over 200 schemes: ftp, file, data
                // and chrome all pass it. The narrowing has been the supported spelling since 9.44.
                //
                // Without it a foreign scheme was refused only by the SSRF guard, which sits after
                // the registration rate limiter has already spent a token, the lock has been taken
                // and the endpoint cap queried. So a typo cost the tenant part of their
                // registration budget and returned the generic "cannot be used as an endpoint"
                // instead of the field message that exists for exactly this. The guard stays the
                // authority; the rule only takes from it the cases that never needed to reach it.
                'url' => ['required', 'url:http,https', 'max:2048'],
                // Constrained to the catalog, and only when the host keeps one. The catalog
                // ships EMPTY, so an `in` rule applied unconditionally would refuse every
                // registration the day an application upgrades; null means "no catalog, no
                // constraint".
                //
                // What a populated catalog constrains is REGISTRATION, not dispatch: the
                // fan-out never consults it, so an application can still emit a type it does
                // not document. What it buys is that this form stops accepting a type it
                // never offered — a typo like `user.registred` produced an endpoint that
                // looked configured, stayed silent, and was indistinguishable from a correct
                // registration until someone noticed weeks of nothing arriving.
                'eventTypes' => ['required', 'array', 'min:1'],
                'eventTypes.*' => $accepted === null ? ['string'] : ['string', Rule::in($accepted)],
            ],
            [
                'name.max' => __('webhooks::self-service.validation.name.max'),
                'url.required' => __('webhooks::self-service.validation.url.required'),
                'url.url' => __('webhooks::self-service.validation.url.url'),
                'url.max' => __('webhooks::self-service.validation.url.max'),
                'eventTypes.required' => __('webhooks::self-service.validation.event_types.required'),
                'eventTypes.min' => __('webhooks::self-service.validation.event_types.min'),
                // Named explicitly, like every other message here, so a refused save does not
                // fall back to the framework's untranslated ":attribute is invalid".
                //
                // The line below was the one rule of this set that had no message, so the paragraph
                // above was false for exactly it. A non-string element rendered "The eventTypes.0
                // field must be a string.", in English whatever the locale, and carrying a raw
                // field path into a screen a customer reads.
                //
                // The three attribute labels below could not soften it either: Laravel does not
                // resolve an indexed key onto its parent's label, so the path appeared as written.
                // See the note beside them.
                'eventTypes.*.string' => __('webhooks::self-service.validation.event_types.string'),
                'eventTypes.*.in' => __('webhooks::self-service.validation.event_types.in'),
            ],
            // These three are inert today, and that is worth knowing rather than trusting. Every
            // message above is written without `:attribute`, so no label is ever interpolated;
            // removing each in turn leaves the suite green.
            //
            // They are kept, not deleted, because the set of messages above is what makes
            // them inert, and that set is edited: the moment a rule here loses its own message, the
            // framework's default fires and asks for exactly these. What they cannot do is rescue
            // an indexed key — `eventTypes.0` does not resolve onto the `eventTypes` label — which
            // is why the rule above needed its own message instead.
            [
                'name' => __('webhooks::self-service.form.name_label'),
                'url' => __('webhooks::self-service.form.url_label'),
                'eventTypes' => __('webhooks::self-service.form.event_types_label'),
            ],
        );

        if ($this->endpointId === null) {
            $this->createEndpoint();

            return;
        }

        $this->updateEndpoint();
    }

    private function createEndpoint(): void
    {
        $this->authorize('create', WebhookSubscription::class);

        // Before the lock, not inside it: a tenant that is already over its allowance has
        // no business making everyone behind it wait for a lock it will be refused under.
        if ($this->registrationRateExceeded()) {
            $this->addError('url', __('webhooks::self-service.registration_throttled'));

            return;
        }

        try {
            // The cap check moved INSIDE the lock, and that is the whole point: read
            // outside it, the count is a fact about a moment that has already passed by
            // the time the insert runs, and two concurrent registrations both pass it.
            //
            // Each refusal names itself on the field where it happens and returns null, so
            // the caller only has to distinguish "registered" from "did not" — and the two
            // reasons cannot be confused for one another on the way out.
            $subscription = $this->withRegistrationLock(function (): ?WebhookSubscription {
                if ($this->endpointCapReached()) {
                    $this->addError('url', __('webhooks::self-service.limit_reached'));

                    return null;
                }

                try {
                    // Register through the manager so the URL is SSRF-vetted and the signing
                    // secret is generated and encrypted-at-rest by the existing scheme. The
                    // new endpoint is owned by the SAME tenant identity the read scope
                    // resolves, so create and filter can never diverge onto different owner
                    // columns.
                    return Webhooks::subscribe(
                        SubscriptionScope::currentOwner(),
                        $this->url,
                        array_values($this->eventTypes),
                        $this->name !== '' ? $this->name : null,
                    );
                } catch (BlockedDestination) {
                    $this->addError('url', $this->blockedUrlMessage());

                    return null;
                }
            });
        } catch (LockTimeoutException) {
            // Another registration of this tenant's held the lock past the wait. Not the
            // cap — saying "you are at your limit" here would be a lie the tenant cannot
            // act on, and the honest instruction is simply to try again.
            $this->addError('url', __('webhooks::self-service.limit_busy'));

            return;
        }

        if (! $subscription instanceof WebhookSubscription) {
            return;
        }

        if (! $this->isActive) {
            Webhooks::disable($subscription);
        }

        $this->finish(__('webhooks::self-service.toast.endpoint_registered'));

        // Reveal the freshly generated secret once, subject to the reveal TTL.
        $this->dispatch('reveal-secret', id: $subscription->id);
    }

    private function updateEndpoint(): void
    {
        // The cast answers the declared `?int` and the null check above it, not the value: past
        // that check the property is already an int. It changes no lookup.
        $subscription = $this->findOwnedEndpoint((int) $this->endpointId);
        // Redundant with the boot gate, and deliberately kept: {@see InteractsWithEndpoints}
        // states the rule and its measurement -- the gate reads the same ability this policy
        // consults, and the ownership it adds is already enforced by the scoped lookup. This is
        // what still refuses if a future caller reaches the action without that lookup.
        $this->authorize('update', $subscription);

        try {
            // Re-vet the (possibly changed) URL before repointing the endpoint.
            $this->ssrfGuard()->resolveAndPin($this->url);
        } catch (BlockedDestination) {
            $this->addError('url', $this->blockedUrlMessage());

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

        $this->finish(__('webhooks::self-service.toast.endpoint_updated'));
    }

    /**
     * The URL error a tenant reads when the SSRF guard refuses the destination. The
     * guard's own message names the resolved host and address, which would turn this
     * form into a probe oracle; the tenant is told the one thing it can act on — the
     * endpoint must be a publicly reachable https URL — in its own language. The
     * guard's precise reason still reaches the operator through the exception itself.
     */
    private function blockedUrlMessage(): string
    {
        return __('webhooks::self-service.validation.url.blocked');
    }

    private function finish(string $message): void
    {
        $this->resetForm();
        $this->open = false;
        $this->dispatch('endpoint-saved');
        $this->dispatch('wirekit-toast', variant: 'success', message: $message);
    }

    private function resetForm(): void
    {
        $this->reset(['endpointId', 'name', 'url', 'eventTypes', 'isActive']);
        $this->resetValidation();
    }

    /**
     * What the endpoint currently being edited already holds, read from the ROW.
     *
     * Not a property, and that is the whole point. Every public property of a Livewire
     * component is writable from the browser, so a remembered set kept in component state
     * would be an allowlist the client can extend: one extra key in the update payload and
     * the catalog rule accepts anything. Nothing about this value belongs to the request —
     * it belongs to the row — so it is read from the row, on the request that needs it.
     *
     * Reading it here rather than spreading `$this->eventTypes` also keeps the offered list
     * out of client control: that property is wire:model-bound and may hold anything at all,
     * including a nested array, which `array_unique` answers with a warning Laravel promotes
     * to an uncaught error.
     *
     * @return list<string>
     */
    #[Computed]
    private function storedEventTypes(): array
    {
        if ($this->endpointId === null) {
            return [];
        }

        // Removing this cannot change an outcome: every caller either tests the value for emptiness
        // or spreads it into a list that is re-indexed again, so the keys never reach anything that
        // reads them. It is kept for the same reason as the pair above — it is what makes the
        // return value a list, which is how its type is declared and how every reader will treat
        // it.
        return $this->findOwnedEndpoint($this->endpointId)->eventTypeNames();
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::self-service.livewire.endpoint-form', [
            // The catalog, plus anything the opened row already holds that the catalog no longer
            // declares. Without the second half the stale value has no checkbox, so a tenant can
            // neither keep it nor drop it — the value is in the component's state and Livewire's
            // checkbox binding only ever adds or removes its own value.
            //
            // The array_values here cannot change what a reader sees, because the view iterates and
            // never reads a key. Its neighbor array_unique is not in that position. Both stay: the
            // value is handed on as a list, and a gap-keyed one would surprise the next reader.
            'availableEventTypes' => array_values(array_unique([
                ...new Settings()->eventTypes(),
                ...$this->storedEventTypes(),
            ])),
        ]);
    }
}
