<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Facades\Webhooks;
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
 * - **It reads no payload, ever, and no error text unless the host asked for one.** The
 *   outbound payload is gated behind its own ability on the operator dashboard and is never
 *   fetched here at all. `error` carries an HTTP client's exception message and can quote
 *   back whatever the receiver wrote — on the endpoint OWNER's own screen that text is the
 *   answer they came for, on a portal where the reader does not run the receiver it is
 *   someone else's server talking, and which of the two this is embedded on is the host's
 *   knowledge. So it is a config key, the property may only ever decline it, and the column
 *   is not even SELECTed while it is off: a promise kept by the view alone is a promise
 *   about the markup rather than about what was read.
 *
 * - **It is bounded in time by default.** webhook_deliveries is range-partitioned by month,
 *   and a read with no lower bound on created_at cannot be pruned, so it visits every
 *   partition there is. Nothing goes red about that — the page loads, it just loads with the
 *   whole history in the plan, and the cost arrives with the DATA rather than with the
 *   change. A filter with no default is not a filter, it is an offer.
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
     * The shipped window ceiling, repeated here because an ABSENT key reads as null and a
     * null ceiling would switch the bound off — the one direction that is expensive and
     * silent. A host on a config cache built before this version is bounded in the meantime.
     * ConfigDefaultsAreInSyncTest holds the two numbers together.
     */
    private const int WINDOW_DAYS = 30;

    /**
     * The window lengths offered, before the host's ceiling trims them.
     */
    private const array WINDOW_STEPS = [7, 30, 90, 365];

    /**
     * Narrow the list to a single endpoint, or null for every endpoint the tenant owns.
     */
    public ?int $endpointId = null;

    /**
     * How many days back the list reads, or null to take the host's configured ceiling.
     *
     * This one IS public, unlike the page size above, because narrowing the window is a
     * thing a reader legitimately does. What makes that safe is the clamp: the value can
     * only ever move the window IN, never past `platform.deliveries.window_days`. A property
     * that could widen it would be a way for the browser to ask for exactly the unbounded
     * scan the default exists to prevent.
     */
    public ?int $windowDays = null;

    /**
     * Whether to render the stored error text, or null to follow the host's config.
     *
     * Also narrowing-only, and for a sharper reason than the window: `error` carries an HTTP
     * client's exception message and can quote back whatever the receiver wrote. An
     * embedding may switch it OFF where the host allows it; nothing sent from a browser may
     * switch it ON.
     */
    public ?bool $showErrors = null;

    /** A message for the reader — why a replay was refused. */
    public string $message = '';

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

    /**
     * Same reason as the endpoint filter: page 3 of ninety days is rarely page 3 of seven,
     * and a narrowed list that opens on an empty page reads as "nothing was sent".
     */
    public function updatingWindowDays(): void
    {
        $this->resetPage($this->getPageName());
    }

    /**
     * Replay one delivery to the endpoint it was sent to.
     *
     * This is the action the three delivery lists between them could not offer a tenant: the
     * per-endpoint view is exactly the screen someone is standing on when they want to send
     * it again.
     *
     * Four guards, in this order, and each answers a different question:
     *
     * 1. The delivery is loaded through the OWNER-SCOPED query, so a foreign id resolves to
     *    nothing and fails not-found before anything else runs — the same shape the endpoint
     *    lookup has, and the reason a probe cannot tell a foreign row from an absent one.
     * 2. The endpoint is loaded through {@see findOwnedEndpoint()}, which scopes again.
     * 3. The `redeliver` ability is the row-level, defense-in-depth check on the ACTION.
     * 4. The per-tenant allowance, because this button makes the server send an HTTP request
     *    to a URL the reader controls.
     *
     * A disabled endpoint is answered with a sentence rather than an exception: the engine
     * refuses it regardless, so the only thing decided here is whether the reader is told.
     */
    public function redeliver(string $id): void
    {
        $this->message = '';

        $delivery = $this->deliveryQuery()->select('*')->whereKey($id)->firstOrFail();
        $endpoint = $this->findOwnedEndpoint($delivery->subscription_id);

        $this->authorize('redeliver', $endpoint);

        if (! $endpoint->is_active) {
            $this->message = __('webhooks::self-service.deliveries.endpoint_disabled');

            return;
        }

        if ($this->replayRateExceeded()) {
            $this->message = __('webhooks::self-service.deliveries.replay_throttled');

            return;
        }

        Webhooks::redeliver($delivery);
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
            'windowChoices' => $this->windowChoices(),
            'showsErrors' => $this->showsErrors(),
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
        // Only the columns the panel renders, and `error` only when it is actually rendered.
        // The class promises to read no BODY of any kind, and a bare query would fetch the
        // jsonb payload on every page — a promise kept by the view alone is a promise about
        // the markup, not about what was read.
        //
        // ⚠️ `subscription_id` was in this list with a comment saying the replay action needed
        // it. It did not: redeliver() calls `->select('*')`, which REPLACES the column list
        // rather than adding to it, so the row it works from was always complete. Measured by
        // dropping the column with both panel suites running.
        $columns = ['id', 'event_type', 'status', 'response_code', 'created_at'];

        if ($this->showsErrors()) {
            $columns[] = 'error';
        }

        $query = WebhookDelivery::query()->select($columns);
        $owner = SubscriptionScope::currentOwner();

        if (! $owner instanceof TenantIdentity) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('owner_type', $owner->type)->where('owner_id', $owner->id);

        if ($this->endpointId !== null) {
            $query->where('subscription_id', $this->findOwnedEndpoint($this->endpointId)->id);
        }

        // The lower bound is what makes this query prunable. webhook_deliveries is range
        // partitioned by month — the package's core storage decision, the one that makes
        // retention a DROP PARTITION rather than a DELETE — and a read with no bound on
        // created_at cannot be pruned, so it visits every partition there is. Nothing goes
        // red about that: the page loads, it just loads with the whole history in the plan,
        // and the cost arrives with the DATA rather than with the change.
        //
        // Bound through the package's own scope rather than a bare where(), because a naive
        // literal is resolved by PostgreSQL against the database SESSION zone.
        $days = $this->effectiveWindowDays();

        if ($days !== null) {
            $query->createdAfter(Date::now()->subDays($days)->startOfDay());
        }

        return $query;
    }

    /**
     * The window in days, or null when the host switched the bound off entirely.
     *
     * The configured value is a CEILING, not merely a default: `windowDays` may narrow
     * beneath it and may not reach past it. A non-positive ceiling is the deliberate opt-out
     * — a host that would rather pay the unbounded scan than ever hide a row.
     */
    private function effectiveWindowDays(): ?int
    {
        $ceiling = Config::integer('webhooks.platform.deliveries.window_days', self::WINDOW_DAYS);

        if ($ceiling <= 0) {
            return null;
        }

        return is_int($this->windowDays) && $this->windowDays > 0
            ? min($this->windowDays, $ceiling)
            : $ceiling;
    }

    /**
     * The window lengths offered to the reader: the shipped steps that fit under the host's
     * ceiling, plus the ceiling itself, so the widest choice is always reachable.
     *
     * @return list<int>
     */
    private function windowChoices(): array
    {
        $ceiling = $this->effectiveWindowDays();

        if ($ceiling === null) {
            return [];
        }

        $choices = array_values(array_filter(self::WINDOW_STEPS, fn (int $days): bool => $days < $ceiling));
        $choices[] = $ceiling;

        return $choices;
    }

    /**
     * Whether the stored error text is rendered.
     *
     * The config decides IF it may be, the property may only decline. `error` carries an HTTP
     * client's exception message and can quote back whatever the receiver wrote — on the
     * endpoint owner's own surface that is the answer they came for, but which surface this
     * is embedded on is the host's knowledge, not the browser's.
     */
    private function showsErrors(): bool
    {
        return Config::boolean('webhooks.platform.deliveries.show_errors', false)
            && ($this->showErrors ?? true);
    }
}
