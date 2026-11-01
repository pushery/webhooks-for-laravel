<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\On;
use Livewire\Component;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\Platform\Support\EventTypeAbilities;
use Pushery\Webhooks\Platform\Support\SelfServiceEndpoints;
use Pushery\Webhooks\Platform\Support\SubscriptionScope;
use Pushery\Webhooks\Support\Settings;

/**
 * Create or edit a single endpoint. Opened by the list via the new-endpoint /
 * edit-endpoint events; a save either registers a fresh endpoint (generating and
 * storing its signing secret through the manager's existing scheme, then revealing it
 * once) or updates an owned one, both through {@see SelfServiceEndpoints}. The URL is
 * SSRF-vetted before it is stored on both paths, so a tenant can never register or
 * repoint an endpoint at an internal address.
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

        $refusal = $this->registrationRefusal(__('webhooks::self-service.limit_reached'));

        if ($refusal !== null) {
            $this->dispatch('wirekit-toast', variant: 'warning', message: $refusal);

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
        // Redundant with the boot gate, and deliberately kept: {@see InteractsWithEndpoints}
        // states the rule and its measurement -- the gate reads the same ability this policy
        // consults, and the ownership it adds is already enforced by the scoped lookup. This is
        // what still refuses if a future caller reaches the action without that lookup.
        $this->authorize('update', $subscription);

        $this->endpointId = $subscription->id;
        $this->name = $subscription->name ?? '';
        $this->url = $subscription->url;
        // Normalized on the way in — see EventTypeList. The tenant-facing form has the same
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
     * Validate and persist through {@see SelfServiceEndpoints}, the class a host's own API saves through as well: a new
     * endpoint is registered for the tenant in scope, an existing one is changed in place. Every refusal it raises is a
     * ValidationException on its field, which Livewire turns into the form's errors, so the form re-renders with the
     * message.
     *
     * Each path authorizes first: create against the manage ability and the tenant in scope, edit against the
     * row-level policy for the owned endpoint.
     */
    public function save(): void
    {
        $endpoints = new SelfServiceEndpoints;

        if ($this->endpointId === null) {
            $this->authorize('create', WebhookSubscription::class);

            $subscription = $endpoints->register(
                // The create policy has just refused a reader without a tenant in scope.
                SubscriptionScope::currentOwner() ?? throw new AuthorizationException,
                $this->url,
                $this->eventTypes,
                $this->name,
                $this->isActive,
                Auth::user(),
            );

            $this->finish(__('webhooks::self-service.toast.endpoint_registered'));

            // Reveal the freshly generated secret once, subject to the reveal TTL.
            $this->dispatch('reveal-secret', id: $subscription->id);

            return;
        }

        $subscription = $this->findOwnedEndpoint($this->endpointId);
        // Redundant with the boot gate, and deliberately kept: {@see InteractsWithEndpoints}
        // states the rule and its measurement -- the gate reads the same ability this policy
        // consults, and the ownership it adds is already enforced by the scoped lookup. This is
        // what still refuses if a future caller reaches the action without that lookup.
        $this->authorize('update', $subscription);

        $endpoints->change($subscription, $this->url, $this->eventTypes, $this->name, $this->isActive, Auth::user());

        $this->finish(__('webhooks::self-service.toast.endpoint_updated'));
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
     * What the endpoint currently being edited already holds, read from the row, for the list
     * of types the form offers. A save reads it from the row again, in
     * {@see SelfServiceEndpoints::change()}.
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
    private function storedEventTypes(): array
    {
        if ($this->endpointId === null) {
            return [];
        }

        // eventTypeNames() hands back a list, which is how this method's type is declared and how
        // the render below treats it.
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
            //
            // A type whose catalog entry names an ability the reader lacks is not offered. One the
            // endpoint already carries still is, so the reader can keep it or remove it.
            'availableEventTypes' => array_values(array_unique([
                ...$this->offeredEventTypes(),
                ...$this->storedEventTypes(),
            ])),
        ]);
    }

    /**
     * The catalog's event types the acting reader may subscribe an endpoint to.
     *
     * @return list<string>
     */
    private function offeredEventTypes(): array
    {
        $types = new Settings()->eventTypes();
        $refused = new EventTypeAbilities()->refused($types, Auth::user());

        return array_values(array_diff($types, $refused));
    }
}
