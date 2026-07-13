<?php

declare(strict_types=1);

namespace Webhooks\Platform\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\On;
use Livewire\Component;
use Webhooks\Core\Http\Exceptions\BlockedDestination;
use Webhooks\Facades\Webhooks;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Webhooks\Platform\Support\SubscriptionScope;

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
        $this->authorize('update', $subscription);

        $this->endpointId = $subscription->id;
        $this->name = $subscription->name ?? '';
        $this->url = $subscription->url;
        $this->eventTypes = $subscription->event_types;
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
        $this->validate(
            [
                'name' => ['nullable', 'string', 'max:255'],
                'url' => ['required', 'url'],
                'eventTypes' => ['required', 'array', 'min:1'],
                'eventTypes.*' => ['string'],
            ],
            [
                'name.max' => __('webhooks::self-service.validation.name.max'),
                'url.required' => __('webhooks::self-service.validation.url.required'),
                'url.url' => __('webhooks::self-service.validation.url.url'),
                'eventTypes.required' => __('webhooks::self-service.validation.event_types.required'),
                'eventTypes.min' => __('webhooks::self-service.validation.event_types.min'),
            ],
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

        if ($this->endpointCapReached()) {
            $this->addError('url', __('webhooks::self-service.limit_reached'));

            return;
        }

        try {
            // Register through the manager so the URL is SSRF-vetted and the signing
            // secret is generated and encrypted-at-rest by the existing scheme. The new
            // endpoint is owned by the SAME tenant identity the read scope resolves, so
            // create and filter can never diverge onto different owner columns.
            $subscription = Webhooks::subscribe(
                SubscriptionScope::currentOwner(),
                $this->url,
                array_values($this->eventTypes),
                $this->name !== '' ? $this->name : null,
            );
        } catch (BlockedDestination) {
            $this->addError('url', $this->blockedUrlMessage());

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
        $subscription = $this->findOwnedEndpoint((int) $this->endpointId);
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

    public function render(): View
    {
        return ViewFactory::make('webhooks::self-service.livewire.endpoint-form', [
            'availableEventTypes' => array_keys(Config::array('webhooks.platform.catalog', [])),
        ]);
    }
}
