<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Enums\DeliveryStatus;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\Platform\Support\ReadableEndpoints;
use Pushery\Webhooks\Platform\Support\SubscriptionScope;
use Pushery\Webhooks\Server\Exceptions\DeliveryRefused;
use Pushery\Webhooks\Support\CalendarDay;
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
     * The most endpoints the filter offers, and the reason it offers a bounded number at all.
     *
     * This select was filled with every endpoint the tenant owns, every column, on EVERY
     * render of the panel — a filter change, a page change, a sibling event. The operator
     * console solved the identical problem carefully and says so in its own words; this is the
     * tenant-facing copy of the same control, and it had neither the cap nor the select().
     *
     * When it truncates the view SAYS SO. A short list that looks complete is how a reader
     * concludes an endpoint has no deliveries when it was simply never offered.
     */
    private const int ENDPOINT_OPTIONS = 200;

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
     * The endpoint this instance belongs to, or null for a panel that spans the tenant.
     *
     * #[Locked] because it is the only narrowing here the reader must not be able to move.
     * $endpointId below is a filter over rows they may see anyway, so a tampered value costs
     * nothing; this one is what a host embeds INSTEAD of trusting that filter, which makes a
     * writable version of it exactly as useless as the filter it replaces.
     *
     * It narrows and never grants. The pin is applied as its own condition beside the owner
     * scoping rather than in place of it, so an endpoint the reader may not see yields an
     * empty list rather than access to it — a host that pins the wrong resource gets nothing
     * back, which is the direction a scoping mistake has to fail in.
     *
     * The case: a surface cut per RESOURCE rather than per account. There the panel belongs to
     * one destination and who may read it hangs off a policy over that destination, so the
     * static resolvers beside it — which answer for the whole request — can only be satisfied
     * by declaring every endpoint the account may read anywhere and then leaning on the filter.
     * A filter is not an authorization, and the host cannot narrow the resolver just before
     * rendering either: a Livewire update request never runs the page build that would do it.
     */
    #[Locked]
    public ?int $pinnedEndpointId = null;

    /**
     * Bind this instance to one endpoint:
     *
     *     <livewire:webhooks.self-service.endpoint-deliveries :subscription="$subscription" />
     *
     * An id is accepted for a host holding one without the model. Nothing is stored but the
     * id: a component property is serialized into every subsequent request, and a whole model
     * riding along there is both larger and a second copy of state the database already has.
     */
    public function mount(WebhookSubscription|int|null $subscription = null): void
    {
        // One line rather than three, and that is about the coverage floor rather than taste:
        // the else branch of a ternary written across three lines is never marked as executed,
        // so no test can close it and a 100% floor holds the release over a branch that ran.
        $this->pinnedEndpointId = $subscription instanceof WebhookSubscription ? $subscription->id : $subscription;
    }

    /**
     * Narrow the list to a single endpoint, or null for every endpoint the tenant owns.
     */
    public ?int $endpointId = null;

    /**
     * Whether an endpoint id that no longer resolves closes the list instead of dropping out
     * of it.
     *
     * The default is the behavior every host has today: the filter is a NARROWING over rows
     * the reader may see anyway, so losing it widens the list back to the tenant's own
     * deliveries, which is a plausible reset and shows nothing that was hidden.
     *
     * On an owner's surface that reading is backwards, and that is what this switch is for.
     * There the endpoint is not a filter over a permitted set — it is the definition of the
     * permitted set. When it stops resolving, "nothing" is the correct answer and "everything
     * the tenant owns" is the wrong one, so a reload after a deletion would show deliveries
     * belonging to other destinations.
     *
     * And it is the failure direction that stays quiet: a filter that falls back to empty
     * looks exactly like a filter somebody cleared. Nothing goes red, no test fails, and the
     * rows are real -- they answer a different question than the one that was asked.
     *
     * #[Locked] because the dangerous direction here is turning it OFF. Every other narrowing
     * property on this class may only ever narrow further, so a value from the browser can
     * cost the reader information but never hand them any; this one is the opposite, and a
     * plain public property would let the browser widen exactly what the host switched on to
     * keep closed. The host sets it at mount and nothing from a request can move it.
     */
    #[Locked]
    public bool $strictEndpoint = false;

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

    /**
     * Narrow to one delivery outcome, or '' for every outcome.
     *
     * An unrecognized value is ignored rather than passed to the query. Passing it through
     * would filter on a status nothing can have and render an empty table — which on this
     * panel reads as "this endpoint received nothing", the one wrong answer a delivery log
     * must never give. {@see DeliveryStatus} is the authority on what exists, so a status
     * added later needs no second list here.
     */
    public string $status = '';

    /**
     * Inclusive lower and upper day bounds, `YYYY-MM-DD`, or '' for unbounded.
     *
     * These cannot widen the window, because they are added to it rather than used instead of it.
     * The window's own lower bound stays on the query unconditionally, so a `from` older than the
     * host's ceiling is ANDed with it and the older of the two simply loses. That is what keeps
     * this pair safe on a panel where `windowDays` had to be clamped by hand: a reader may ask a
     * narrower question, never a wider one.
     *
     * The trap is the plausible tidy-up — replacing the window bound with `from` when one is
     * given, so the query carries a single lower bound. That reads cleaner and hands the
     * browser the unbounded scan the window exists to prevent.
     */
    public string $from = '';

    public string $until = '';

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
        // Under $strictEndpoint the id deliberately STAYS, and dropping it here would defeat
        // the switch before the query ever sees it: an id that is gone must close the list,
        // and a null id is indistinguishable from "no filter was ever set". The 404 loop this
        // reset exists to prevent cannot happen there either, because the strict path resolves
        // the endpoint leniently rather than through the not-found lookup.
        if (! $this->strictEndpoint && $this->endpointId !== null && ! $this->scopedQuery()->whereKey($this->endpointId)->exists()) {
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

    /** Same reason again: page 3 of every outcome is rarely page 3 of the failures. */
    public function updatingStatus(): void
    {
        $this->resetPage($this->getPageName());
    }

    /** And again for either day bound. */
    public function updatingFrom(): void
    {
        $this->resetPage($this->getPageName());
    }

    public function updatingUntil(): void
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
     * 2. The endpoint is loaded through {@see self::findOwnedEndpoint()}, which scopes again.
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

        // Redundant with the boot gate, and deliberately kept: {@see InteractsWithEndpoints}
        // states the rule and its measurement in full -- the gate reads the same ability this
        // policy consults, and the ownership the policy adds is already enforced by the scoped
        // lookup above. This is what still refuses if a future caller reaches the action without
        // that lookup.
        $this->authorize('redeliver', $endpoint);

        if (! $endpoint->is_active) {
            $this->message = __('webhooks::self-service.deliveries.endpoint_disabled');

            return;
        }

        if ($this->replayRateExceeded()) {
            $this->message = __('webhooks::self-service.deliveries.replay_throttled');

            return;
        }

        // The check above reads the endpoint this panel loaded; redeliver() re-reads the
        // subscription off the delivery and checks again. Between the two the endpoint can be
        // switched off -- by the circuit breaker on a concurrent failure, or by the tenant in
        // another tab -- and then the refusal arrives here as an exception.
        //
        // That window is small and it is not hypothetical: the breaker disables an endpoint
        // precisely while its deliveries are failing, which is exactly when somebody is looking
        // at this list and pressing Send again. Uncaught it is a 500 over an ordinary outcome.
        try {
            Webhooks::redeliver($delivery);
        } catch (DeliveryRefused) {
            $this->message = __('webhooks::self-service.deliveries.endpoint_disabled');
        }
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

        // One row past the cap, so the truncation is DETECTABLE rather than assumed. Asking for
        // MORE than one extra changes nothing observable -- the flag is a comparison against the
        // cap and the view takes exactly the cap -- so only the +0 and the -1 readings are real.
        $endpoints = $this->endpointChoices();

        $endpointsTruncated = $endpoints->count() > self::ENDPOINT_OPTIONS;

        return ViewFactory::make('webhooks::self-service.livewire.endpoint-deliveries', [
            'deliveries' => $deliveries,
            'endpoints' => $endpoints->take(self::ENDPOINT_OPTIONS),
            'endpointsTruncated' => $endpointsTruncated,
            'windowChoices' => $this->windowChoices(),
            'statusChoices' => DeliveryStatus::cases(),
            'emptyStateKey' => $this->emptyStateKey(),
            'showsErrors' => $this->showsErrors(),
        ]);
    }

    /**
     * The endpoints offered in the filter: the ones the reader owns, plus the ones a host has
     * declared readable. One query rather than two merged lists, so the ordering and the cap
     * mean the same thing they meant before -- a merge would have had to re-sort, and a cap
     * applied twice is not the cap.
     *
     * The owned half stays a subquery on the scoped builder instead of a repeated owner
     * predicate: there is one definition of "owned" in this package and this is not the place
     * to grow a second.
     *
     * @return Collection<int, WebhookSubscription>
     */
    private function endpointChoices(): Collection
    {
        // A pinned panel offers none. A select whose only usable option is the endpoint already
        // pinned is a control that cannot do anything, and a reader who moves it and sees the
        // list stay put learns the screen is unreliable. The view renders the filter only when
        // this is non-empty, so returning nothing removes the control rather than disabling it.
        if ($this->pinnedEndpointId !== null) {
            return new Collection;
        }

        $readable = ReadableEndpoints::ids();

        if ($readable === []) {
            return $this->scopedQuery()
                ->select(['id', 'url', 'name'])
                ->latest()
                ->limit(self::ENDPOINT_OPTIONS + 1)
                ->get();
        }

        return WebhookSubscription::query()
            ->select(['id', 'url', 'name'])
            ->where(fn (Builder $scope): Builder => $scope
                ->whereIn('id', $this->scopedQuery()->select('id'))
                ->orWhereIn('id', $readable))
            ->latest()
            ->limit(self::ENDPOINT_OPTIONS + 1)
            ->get();
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
        return $this->filteredQuery()
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
        // `subscription_id` was in this list with a comment saying the replay action needed it. It
        // did not: redeliver() calls `->select('*')`, which replaces the column list rather than
        // adding to it, so the row it works from was always complete. Measured by dropping the
        // column with both panel suites running.
        // duration_ms rides along with response_code because it is only ever rendered beside
        // one: a duration with no answer behind it is the time spent failing to get one, and
        // reading it as latency would be wrong in the direction that looks reassuring.
        $columns = ['id', 'event_type', 'status', 'response_code', 'duration_ms', 'created_at'];

        if ($this->showsErrors()) {
            $columns[] = 'error';
        }

        $query = WebhookDelivery::query()->select($columns);
        $owner = SubscriptionScope::currentOwner();
        $readable = ReadableEndpoints::ids();

        // Still closed when there is nothing to open it with. The owner pair is the ground set
        // and a host may ADD to it (see ReadableEndpoints) -- with neither an owner nor a
        // declared readable endpoint the panel constrains to nothing rather than to everything,
        // which is the property this line has always had and the one worth not losing.
        if (! $owner instanceof TenantIdentity && $readable === []) {
            return $query->whereRaw('1 = 0');
        }

        // Grouped, because an ungrouped `orWhereIn` beside the later filters would bind them to
        // the OR rather than to the whole set -- a window or status filter would then widen the
        // result instead of narrowing it, which is the one direction this panel must not move in.
        $query->where(function (Builder $scope) use ($owner, $readable): void {
            if ($owner instanceof TenantIdentity) {
                $scope->where(fn (Builder $pair): Builder => $pair
                    ->where('owner_type', $owner->type)
                    ->where('owner_id', $owner->id));
            }

            if ($readable !== []) {
                $scope->orWhereIn('subscription_id', $readable);
            }
        });

        // Its own condition, ANDed against the scope above rather than folded into it. That is
        // what makes it incapable of widening: whatever the group admits, this can only cut
        // down. The reader's own filter steps aside while it is set, because a second
        // subscription_id predicate could only ever agree with this one or empty the list.
        if ($this->pinnedEndpointId !== null) {
            $query->where('subscription_id', $this->pinnedEndpointId);
        } elseif ($this->endpointId !== null) {
            // The lenient lookup is the strict path's, and the pairing is the wrong way round
            // only until you read what each mode is protecting.
            //
            // By default an id that does not resolve is a TAMPERED id -- the reset above has
            // already removed the one legitimate way to hold a stale one -- so the not-found
            // lookup is right: an empty table would look like an answer about an endpoint the
            // reader does not own.
            //
            // Under $strictEndpoint a stale id is the ORDINARY case, because the reset no
            // longer runs, and 404-ing the panel for the rest of the session over a deletion
            // somebody performed on purpose is not an answer either. Both a deleted endpoint
            // and a tampered one close the list, and the trade is stated rather than
            // discovered: this mode gives up telling those two apart, in exchange for never
            // widening. That is the direction a host chooses this switch for.
            // A declared readable endpoint is filterable without the owner-scoped lookup, which
            // would 404 on it -- the reader legitimately does not own it. Checked against the
            // resolved list rather than against the database, so the host's answer is the only
            // thing that can widen this.
            if (in_array($this->endpointId, $readable, true)) {
                $query->where('subscription_id', $this->endpointId);
            } else {
                $endpoint = $this->strictEndpoint
                    ? $this->scopedQuery()->whereKey($this->endpointId)->first()
                    : $this->findOwnedEndpoint($this->endpointId);

                if ($endpoint === null) {
                    return $query->whereRaw('1 = 0');
                }

                $query->where('subscription_id', $endpoint->id);
            }
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
     * Which of the three empty-state sentences is TRUE for the current filters.
     *
     * Three rather than two, and the missing one is the wrong answer a delivery log must never
     * give. The view chose between two on the endpoint filter alone, which was complete until the
     * outcome and day-range filters existed. After them a customer who filters to "failed" and has
     * none was told "nothing has been sent to your endpoints yet", in the exact moment they are
     * looking for a failure somebody told them about. The endpoint wording ("nothing has been sent
     * to this endpoint") is just as untrue there.
     *
     * Decided here rather than in the view, so a filter added later is one line from being
     * counted rather than a sentence that quietly goes stale.
     */
    private function emptyStateKey(): string
    {
        // Narrowed by outcome or by date: the only honest sentence is about the FILTERS. Both
        // others are claims about what was sent, and both are false here.
        if (DeliveryStatus::tryFrom($this->status) instanceof DeliveryStatus
            || CalendarDay::start($this->from) instanceof CarbonInterface
            || CalendarDay::endExclusive($this->until) instanceof CarbonInterface
            || $this->windowNarrowed()) {
            return 'no_match';
        }

        // One endpoint selected: "your endpoints" would be a claim about the others too. A
        // pinned panel is the same sentence for a stronger reason — there the others are not
        // merely unselected, they are not this panel's subject at all.
        if ($this->endpointId !== null || $this->pinnedEndpointId !== null) {
            return 'filtered';
        }

        return 'description';
    }

    /**
     * The tenant's deliveries as the READER has narrowed them.
     *
     * Separate from {@see self::deliveryQuery()} on purpose, and the separation is the fix for a defect
     * these filters introduced. The replay action loads its row through the owner-scoped query, and
     * while these three sat inside that query it inherited them: a tenant who filtered to "failed",
     * saw a row and clicked replay got a 404 on their own delivery whenever a worker had moved it
     * to `exhausted` in between, which is exactly the kind of row somebody replays. What may narrow
     * a lookup is ownership; what the reader chose narrows a list.
     *
     * The dates are added to the window bound rather than used in its place, so a reader's
     * dates can only ever narrow what the window already allows — and they go through the
     * package's own timestamp scopes for the two reasons the operator log states at its own
     * bounds: whereDate() wraps the column in a function and stops the planner pruning
     * partitions, and a bare where() binds a naive literal that PostgreSQL resolves against
     * the database SESSION zone.
     *
     * @return Builder<WebhookDelivery>
     */
    private function filteredQuery(): Builder
    {
        $query = $this->deliveryQuery();

        $from = CalendarDay::start($this->from);

        if ($from instanceof CarbonInterface) {
            $query->createdAfter($from);
        }

        $until = CalendarDay::endExclusive($this->until);

        if ($until instanceof CarbonInterface) {
            $query->createdBefore($until);
        }

        if (DeliveryStatus::tryFrom($this->status) instanceof DeliveryStatus) {
            $query->where('status', $this->status);
        }

        return $query;
    }

    /**
     * Whether the reader pulled the time window in below the host's ceiling.
     *
     * The fourth filter, and the three-way empty state was written without it. `windowDays` is
     * reader-controlled like the other three: narrowed to seven days over an endpoint whose
     * deliveries are twenty days old, the list is empty because of a choice the reader made.
     *
     * That makes the unfiltered sentence worse than merely wrong there. It hedges about the
     * retention window — "an older one may have been here and gone" — so it attributes the reader's
     * own narrowing to the package having deleted their data.
     *
     * Compared against the ceiling rather than against null: the value equal to the ceiling is
     * the default in a different spelling, and calling that a filter would put the wrong
     * sentence in front of a reader who changed nothing.
     */
    private function windowNarrowed(): bool
    {
        if (! is_int($this->windowDays) || $this->windowDays <= 0) {
            return false;
        }

        $ceiling = Config::integer('webhooks.platform.deliveries.window_days', self::WINDOW_DAYS);

        // The left half is redundant against the right: the guard above leaves windowDays
        // positive, so a ceiling of zero or less already fails the comparison. Measured -- every
        // bound this constant can take leaves the suite green. It stays because "a non-positive
        // ceiling means no ceiling" is the rule the method beside it states, and reading it here
        // is cheaper than deriving it from the comparison.
        return $ceiling > 0 && $this->windowDays < $ceiling;
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

        // The re-index answers the declared list type rather than the data: WINDOW_STEPS ascends
        // and the predicate is a single upper bound, so what survives is always a prefix and the
        // keys never gain a hole.
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
