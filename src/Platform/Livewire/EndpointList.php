<?php

declare(strict_types=1);

namespace Webhooks\Platform\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Webhooks\Facades\Webhooks;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;

/**
 * The tenant's own endpoint list: each row shows the URL, an active toggle, a cached
 * health badge, its event-type summary and the edit / reveal-secret / delete actions.
 * Paginated and always scoped to the acting tenant, so a foreign owner's endpoints are
 * never listed. The "New endpoint" action is refused once the tenant hits its cap.
 *
 * Actions that mutate a single endpoint re-resolve it through the owner-scoped query
 * and re-authorize the row-level policy, so the list can never act on a row the tenant
 * does not own.
 */
#[Lazy]
final class EndpointList extends Component
{
    use InteractsWithEndpoints;
    use WithPagination;

    public int $perPage = 10;

    /**
     * Re-render when another panel changes the underlying endpoints, so the list stays
     * in step after a create, edit, delete or secret rotation.
     */
    #[On('endpoint-saved')]
    #[On('endpoint-deleted')]
    public function refreshList(): void
    {
        unset($this->capReached);
    }

    /**
     * Ask the form to open in create mode. Refused when the tenant is at its cap.
     */
    public function newEndpoint(): void
    {
        $this->authorize('create', WebhookSubscription::class);

        if ($this->endpointCapReached()) {
            $this->dispatch('wirekit-toast', variant: 'warning', message: __('webhooks::self-service.limit_reached'));

            return;
        }

        $this->dispatch('new-endpoint');
    }

    /**
     * Ask the form to open in edit mode for one owned endpoint.
     */
    public function edit(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        $this->authorize('update', $subscription);

        $this->dispatch('edit-endpoint', id: $subscription->id);
    }

    /**
     * Ask the secret panel to reveal the signing secret for one owned endpoint.
     */
    public function reveal(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        $this->authorize('view', $subscription);

        $this->dispatch('reveal-secret', id: $subscription->id);
    }

    /**
     * Enable or disable one owned endpoint, through the manager — so the UI cannot drift
     * from what activation means (disabling stamps disabled_at; enabling clears it AND
     * the circuit-breaker streak, or the endpoint would re-disable on its next failure).
     */
    public function toggle(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        $this->authorize('update', $subscription);

        $subscription->is_active
            ? Webhooks::disable($subscription)
            : Webhooks::enable($subscription);

        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.endpoint_updated'));
    }

    /**
     * Permanently remove one owned endpoint, gated by both the row-level policy and the
     * allow_delete switch (the policy already honours the switch).
     */
    public function delete(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        $this->authorize('delete', $subscription);

        $subscription->delete();

        $this->resetPage();
        $this->dispatch('endpoint-deleted');
        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.endpoint_deleted'));
    }

    /**
     * Whether the tenant has reached its endpoint cap, so the "New endpoint" action is
     * hidden. Cached for the request; the list view reads it while it also polls.
     */
    #[Computed]
    public function capReached(): bool
    {
        return $this->endpointCapReached();
    }

    /**
     * Page the list with the package's own pagination control rather than Livewire's
     * built-in one, whose markup paints a raw color palette no design token reaches and
     * whose landmark carries a hardcoded English accessible name.
     */
    public function paginationView(): string
    {
        return 'webhooks::pagination';
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::self-service.placeholders.list');
    }

    public function render(): View
    {
        $endpoints = $this->scopedQuery()->latest()->paginate($this->perPage);

        return ViewFactory::make('webhooks::self-service.livewire.endpoint-list', [
            'endpoints' => $endpoints,
            'allowDelete' => $this->deletionAllowed(),
        ]);
    }
}
