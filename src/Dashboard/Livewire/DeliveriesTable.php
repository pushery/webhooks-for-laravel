<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Webhooks\Dashboard\DashboardScope;
use Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;

/**
 * The full delivery table on the Webhooks tab: tenant-scoped, sortable, paginated
 * and filterable by status and event type, with an inline replay action and a
 * row-open into the detail drawer. An empty result renders the empty state.
 */
#[Lazy]
final class DeliveriesTable extends Component
{
    use InteractsWithDashboard;
    use WithPagination;

    /**
     * Columns a caller may sort by. The active field is validated against this list
     * before it reaches the query, so the order-by clause is never user-controlled.
     */
    private const array SORTABLE = ['created_at', 'event_type', 'status', 'attempt', 'response_code', 'duration_ms'];

    #[Url]
    public string $status = '';

    #[Url]
    public string $eventType = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public int $perPage = 15;

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingEventType(): void
    {
        $this->resetPage();
    }

    /**
     * Sort by a column, flipping direction when the same column is chosen again.
     */
    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Open the detail drawer for one row (scoped to the acting tenant).
     */
    public function viewDelivery(string $deliveryId): void
    {
        $this->dispatch('show-delivery', deliveryId: $deliveryId);
    }

    /**
     * Page the table with the package's own pagination control rather than Livewire's
     * built-in one, whose markup paints a raw color palette no design token reaches and
     * whose landmark carries a hardcoded English accessible name.
     */
    public function paginationView(): string
    {
        return 'webhooks::pagination';
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.table');
    }

    public function render(): View
    {
        $sortField = in_array($this->sortField, self::SORTABLE, true) ? $this->sortField : 'created_at';
        $sortDirection = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $owner = DashboardScope::currentOwner();

        $deliveries = $this->sourceModel()
            ->newQuery()
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->eventType !== '', fn (Builder $query): Builder => $query->where('event_type', 'like', '%'.$this->eventType.'%'))
            ->orderBy($sortField, $sortDirection)
            ->paginate($this->perPage);

        return ViewFactory::make('webhooks::dashboard.livewire.deliveries-table', [
            'deliveries' => $deliveries,
        ]);
    }
}
