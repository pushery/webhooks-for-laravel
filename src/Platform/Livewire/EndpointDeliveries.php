<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\Platform\Support\SubscriptionScope;
use Pushery\Webhooks\Support\TenantIdentity;

/**
 * The tenant's own delivery log: what was sent to their endpoints, when, and what came
 * back. Newest first, paginated, optionally narrowed to one endpoint.
 *
 * Deliberately NOT lazy, unlike its sibling panels. It shares a page with the endpoint list,
 * whose delete confirmation is an alert-dialog Alpine teleports to the body — and a lazy
 * mount is a Livewire round trip that lands whenever the reader happens to scroll. Landing
 * it while that dialog is open tears the teleport down and the dialog disappears mid-decision.
 * Its own query is one indexed read bounded to a page, which is a smaller cost than a modal
 * that vanishes.
 *
 * It exists because the portal could show a customer THAT they had an endpoint and never
 * whether anything had ever reached it. A receiver seeing nothing arrive has exactly two
 * hypotheses — you did not send, or I did not accept — and without this list they cannot
 * rule out the first, so they write to support instead. The endpoint health badge does not
 * answer it either: a score says an endpoint is broadly fine, not whether the order from
 * 14:03 went out.
 *
 * Two properties of the query are load-bearing rather than incidental:
 *
 * - **It is scoped by the delivery row's OWN owner columns, with no join.** Each delivery
 *   carries the denormalized (owner_type, owner_id) of its subscription's owner, so there
 *   is no relation through which the scope could later be widened, and the whole PAIR is
 *   compared because two tenants can share an owner_id under different owner types. With
 *   no tenant resolved it constrains to nothing rather than falling back to everything.
 * - **It reads no body of any kind.** Not the outbound payload, which is gated behind its
 *   own ability on the operator dashboard, and not `error`, which carries an HTTP client's
 *   exception message and can quote back whatever the receiver wrote. A row here is time,
 *   outcome and status code, and nothing that needs a gate is fetched at all.
 */
final class EndpointDeliveries extends Component
{
    use InteractsWithEndpoints;
    use WithPagination;

    /**
     * Not a public property, unlike the sibling panels: every public property of a Livewire
     * component is writable from the browser, and a page size the reader controls is a page
     * size they can set to a value that pages nothing. `-1` reaches Builder::limit(), which
     * drops a non-positive value silently, and the panel would read the tenant's whole
     * delivery history — payload column and all — into memory in one request.
     */
    private const int PER_PAGE = 10;

    /**
     * Narrow the list to a single endpoint, or null for every endpoint the tenant owns.
     */
    public ?int $endpointId = null;

    /**
     * Its own page name, because the portal shows this panel and the endpoint list on one
     * screen and Livewire names both paginators `page` by default — paging one would page
     * the other.
     */
    public function getPageName(): string
    {
        return 'deliveries';
    }

    /**
     * Re-render after a sibling panel changes the endpoints, so a deleted endpoint stops
     * appearing in the filter and its cascaded deliveries leave the list.
     *
     * The filter is dropped when it names an endpoint that is gone. Deleting the endpoint
     * the log is narrowed to is an ordinary thing to do on this page, and leaving the id
     * standing would make every later render resolve a row that no longer exists — the
     * panel would answer 404 for the rest of the session over a deletion the tenant
     * performed deliberately.
     */
    #[On('endpoint-saved')]
    #[On('endpoint-deleted')]
    public function refreshDeliveries(): void
    {
        if ($this->endpointId !== null && ! $this->scopedQuery()->whereKey($this->endpointId)->exists()) {
            $this->endpointId = null;
        }

        $this->resetPage($this->getPageName());
    }

    /**
     * Reset to the first page when the filter changes: page 3 of every endpoint is rarely
     * page 3 of one of them, and a filtered list that opens on an empty page reads as "no
     * deliveries" for an endpoint that has plenty.
     */
    public function updatingEndpointId(): void
    {
        $this->resetPage($this->getPageName());
    }

    public function paginationView(): string
    {
        return 'webhooks::pagination';
    }

    public function render(): View
    {
        $deliveries = $this->page();

        // A reader on page 3 whose tail the retention window drops would otherwise be handed a
        // table with headers and no rows, and no indication of where they are. Put them on the
        // last page that exists instead; the extra query only runs in that state.
        if ($deliveries->isEmpty() && $deliveries->total() > 0) {
            $this->setPage($deliveries->lastPage(), $this->getPageName());

            $deliveries = $this->page();
        }

        return ViewFactory::make('webhooks::self-service.livewire.endpoint-deliveries', [
            'deliveries' => $deliveries,
            'endpoints' => $this->scopedQuery()->latest()->get(),
        ]);
    }

    /**
     * One page of the tenant's deliveries, newest first.
     *
     * Ordered by id as well as time, because created_at has second precision and a fan-out
     * writes a burst of rows inside one of them. Without a tiebreaker the engine is free to
     * order tied rows differently between two page queries, and a reader paging through them
     * is shown some rows twice and never shown others — silently, in the one list whose whole
     * job is to say what did and did not go out.
     *
     * @return LengthAwarePaginator<int, WebhookDelivery>
     */
    private function page(): LengthAwarePaginator
    {
        return $this->deliveryQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, pageName: $this->getPageName());
    }

    /**
     * Deliveries belonging to the acting tenant, narrowed to one endpoint when the filter
     * names one the tenant actually owns.
     *
     * The endpoint filter is resolved through the owner-scoped endpoint lookup rather than
     * used as a bare where(): a tampered id then 404s instead of quietly producing an empty
     * list that looks like an answer. The owner pair stays on the query even once an
     * endpoint id narrows it — the pair is the isolation guarantee, and the index that
     * serves the narrowed read is chosen by the planner either way.
     *
     * @return Builder<WebhookDelivery>
     */
    private function deliveryQuery(): Builder
    {
        // Only the columns the panel renders. The class promises to read no body of any
        // kind, and a bare query would fetch the jsonb payload and the stored error on every
        // page — a promise kept by the view alone is a promise about the markup, not about
        // what was read.
        $query = WebhookDelivery::query()->select(['id', 'event_type', 'status', 'response_code', 'created_at']);
        $owner = SubscriptionScope::currentOwner();

        if (! $owner instanceof TenantIdentity) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('owner_type', $owner->type)->where('owner_id', $owner->id);

        if ($this->endpointId !== null) {
            $query->where('subscription_id', $this->findOwnedEndpoint($this->endpointId)->id);
        }

        return $query;
    }
}
