<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Dashboard\DashboardScope;
use Pushery\Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;

/**
 * The full delivery table on the Webhooks tab: tenant-scoped, sortable, paginated
 * and filterable by status and event type, with an inline replay action and a
 * row-open into the detail drawer. An empty result renders the empty state.
 *
 * `isolate: false` bundles this panel's lazy load with its siblings' into ONE request. Six
 * isolated ones each morph the shared parent, and on a window switch four of them arrive
 * while the components they name are being replaced — which is the `__lazyLoad not found`
 * race. See {@see WebhooksDashboardPage} for the
 * whole chain, including why taking the window out of the keys would be the worse answer.
 */
#[Lazy(isolate: false)]
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

    /**
     * How many days back the table reads, or null to take the host's configured ceiling.
     *
     * Clamped for the same reason `perPage` is: a public Livewire property is writable from
     * the browser, so a value the reader picks is a cost the reader picks. It may narrow the
     * window and can never reach past `dashboard.deliveries.window_days` — widening it would
     * be a way to ask for exactly the unbounded, unprunable read the default prevents.
     */
    #[Url]
    public ?int $windowDays = null;

    /**
     * The shipped ceiling, repeated here because an ABSENT key reads as null and a null
     * ceiling would switch the bound off — the one direction that is expensive and silent.
     * ConfigDefaultsAreInSyncTest holds the two numbers together.
     */
    private const int WINDOW_DAYS = 30;

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingWindowDays(): void
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

        [$ownerSql, $ownerBindings] = DashboardScope::current()->condition();

        $query = $this->sourceModel()
            ->newQuery()
            // The endpoint is read by every row's accessible name, and without this it is read
            // one query at a time. Named columns rather than the whole row: a subscription
            // carries its signing secret, and a table that needs a label has no business
            // hydrating it fifteen times per render.
            ->with(['subscription:id,name,url'])
            ->whereRaw($ownerSql, $ownerBindings);

        // The lower bound is what makes this read prunable. webhook_deliveries is range
        // partitioned by month — the decision that makes retention a DROP PARTITION rather
        // than a DELETE — and a query with no bound on created_at visits every partition
        // there is, on every render of a screen that stays open all day. Nothing goes red:
        // the page loads, it just loads with the whole history in the plan, and the cost
        // arrives with the DATA rather than with the change.
        //
        // Bound through the package's own timestamp scope rather than a bare where(): a
        // naive literal is resolved by PostgreSQL against the database SESSION zone, which
        // is a connection setting unrelated to app.timezone.
        $days = $this->effectiveWindowDays();

        if ($days !== null) {
            $query->createdAfter(Date::now()->subDays($days)->startOfDay());
        }

        $deliveries = $query
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            // The `!== ''` test changes no result: with an empty filter the clause becomes LIKE
            // '%%', which matches every row the panel would have returned anyway.
            //
            // It is NOT redundant for that reason alone — it is what keeps an unfiltered table
            // from carrying a pointless LIKE into the query plan on a partitioned table. Kept
            // for the plan, not for the result.
            ->when($this->eventType !== '', fn (Builder $query): Builder => $query->where('event_type', 'like', '%'.$this->eventType.'%'))
            ->orderBy($sortField, $sortDirection)
            // A tiebreaker rather than a second sort preference. The column above is chosen by the
            // reader, and every one of them ties: sorting by status puts every row of a status
            // level with every other, and `created_at` has second resolution against a fan-out that
            // writes a burst inside one second. A paginated read is several queries. Where the
            // order is not total the database may return tied rows differently per query, so a
            // reader paging through a burst sees rows twice and never sees others, with every
            // individual page correct. The primary key is unique, which is what makes the order
            // total.
            ->orderBy('id', $sortDirection)
            // Clamped, because a public Livewire property is writable from the browser and
            // Builder::limit() silently drops a non-positive value — a page size the reader
            // controls is one they can set to a value that pages nothing, and the component
            // would read every row it can see, in one request.
            ->paginate(max(1, min($this->perPage, 100)));

        return ViewFactory::make('webhooks::dashboard.livewire.deliveries-table', [
            'deliveries' => $deliveries,
        ]);
    }

    /**
     * The window in days, or null when the host switched the bound off entirely.
     *
     * The configured value is a CEILING rather than merely a default; a non-positive one is
     * the deliberate opt-out, for an installation that would rather pay the scan.
     */
    private function effectiveWindowDays(): ?int
    {
        $ceiling = Config::integer('webhooks.dashboard.deliveries.window_days', self::WINDOW_DAYS);

        if ($ceiling <= 0) {
            return null;
        }

        return is_int($this->windowDays) && $this->windowDays > 0
            ? min($this->windowDays, $ceiling)
            : $ceiling;
    }
}
