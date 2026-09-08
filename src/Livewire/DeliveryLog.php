<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Exceptions\TestPingThrottled;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Livewire\Concerns\AuthorizesOperatorActions;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Support\CalendarDay;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Support\UiVariant;

/**
 * The operator view of the delivery log: browse every delivery, filter it, and replay or test-ping
 * one. A published stub — restyle it and make it yours.
 *
 * It is deliberately unscoped, and unauthorized by default: it reads every tenant's deliveries, so
 * it must be embedded behind an operator-only gate of your own. It is not a tenant-facing surface.
 *
 * Its two mutating actions — redeliver() and ping() — additionally honor
 * webhooks.admin.abilities and webhooks.admin.ability (or an overridden authorizeAction())
 * when a host sets one, so the whole console gates the same way rather than only half of it.
 * Left unset, nothing changes.
 *
 * Not `final`, and deliberately so: the override that sentence offers has to be reachable. See
 * {@see AuthorizesOperatorActions} — a permission name in the single ability key denies every
 * action silently wherever permissions resolve through a `Gate::before` hook that takes the first
 * positional argument for a guard, because the action name travels in exactly that position. The
 * map passes no argument at all and is the direct way past it; the subclass remains the way past
 * anything an ability cannot express.
 *
 * The tenant-facing surface is the observability dashboard
 * (`Pushery\Webhooks\Dashboard\Livewire\DeliveriesTable`), which is owner-scoped and
 * policy-guarded.
 */
class DeliveryLog extends Component
{
    use AuthorizesOperatorActions;
    use WithPagination;

    /**
     * At most this many endpoints are offered as filter options. This stub is UNSCOPED, so
     * that list is every subscription in the installation — on a multi-tenant host, one
     * option per customer endpoint. Rendering all of them on every render is a cost nobody
     * asked for, and the reader only ever picks one.
     *
     * When it truncates the view SAYS SO. A short list that looks complete is how a reader
     * concludes an endpoint has no deliveries when it simply was not offered — the same
     * wrong answer this whole surface exists to prevent.
     */
    private const int ENDPOINT_OPTIONS = 200;

    /**
     * The fallback for `ui.deliveries.default_window_days`, so a host that removed the key
     * from a published config still opens on a window rather than on every partition.
     */
    private const int DEFAULT_WINDOW_DAYS = 30;

    public string $status = '';

    public string $eventType = '';

    /**
     * One endpoint's id, or '' for every endpoint. A string rather than an int because it
     * is bound to a <select> whose empty option carries '' — and because every public
     * property here is writable from the browser, so it holds whatever arrives.
     */
    public string $endpointId = '';

    /**
     * The former name of $endpointId, kept working rather than removed.
     *
     * It held an endpoint's id while calling it a subscription, and the two words are not
     * interchangeable to a reader: the portal panel beside it calls the same thing
     * `endpointId`, every option in this filter is described as one customer endpoint, and
     * the docblock above has always said endpoint. A consumer evaluating adoption compares
     * property names — the cheapest comparison and therefore the usual one — and this one
     * read as a filter that did not exist, so the capability was invisible and got built a
     * second time. That is the expensive kind of naming defect: it disguises itself as a
     * missing feature.
     *
     * Renaming alone would have been a breaking change for the wrong people. Both stubs are
     * meant to be PUBLISHED and edited — that is this package's main customization path — and
     * a published copy binds this name in its markup, so dropping it would break the filter in
     * every host that took the package up on its own advice. The two are kept in step in both
     * directions instead, and a host on either name sees no difference.
     *
     * @deprecated Renamed to $endpointId. Bind to that in a published view; this keeps
     *             working, and nothing is planned that removes it before the next major.
     */
    public string $subscriptionId = '';

    /**
     * Inclusive window bounds as 'Y-m-d', or '' for unbounded. Read in the application's
     * timezone: this stub is the operator's own console, not the tenant-facing dashboard,
     * so it has no business resolving a per-tenant zone.
     */
    public string $from = '';

    public string $until = '';

    /**
     * Open on a window rather than on everything.
     *
     * `webhook_deliveries` is range-partitioned by month, so a query with no lower bound on
     * `created_at` cannot be pruned and reads every partition there is. Both bounds started
     * empty, so the FIRST render of a fresh instance did exactly that -- and nothing about it
     * looks wrong: the page loads, and on a young installation it is fast. The cost arrives
     * with the data, months later, and reads as a database problem rather than as a default
     * nobody set.
     *
     * A DEFAULT and not a ceiling, which is the difference between this screen and the two
     * tenant-facing lists. Their properties are writable from the browser by whoever is
     * looking, so `EndpointDeliveries` and `DeliveriesTable` may only ever narrow the window.
     * This is the operator's own console: the date is visible in the From field, and clearing
     * it is a decision the reader takes rather than a state they arrive in.
     *
     * `0` opens the log unbounded, for a host that wants the old behavior back.
     *
     * A range the host passed in wins, and that is the whole reason for the first condition
     * below. This used to set the bound unconditionally, so a link carrying a date range lost
     * it on arrival: the reader opened on the last thirty days and never saw what somebody
     * had sent them, with nothing to suggest a range had been discarded. A default is what
     * happens when nobody said otherwise, and somebody said otherwise here.
     */
    public function mount(): void
    {
        // A host passing the deprecated name gets the same panel. Properties arrive before
        // mount() runs, so this is the one place that sees what was handed in.
        if ($this->subscriptionId !== '' && $this->endpointId === '') {
            $this->endpointId = $this->subscriptionId;
        }

        if ($this->endpointId !== '' && $this->subscriptionId === '') {
            $this->subscriptionId = $this->endpointId;
        }

        if ($this->from !== '') {
            return;
        }

        $days = Config::integer('webhooks.ui.deliveries.default_window_days', self::DEFAULT_WINDOW_DAYS);

        if ($days > 0) {
            $this->from = now()->subDays($days)->toDateString();
        }
    }

    /** A message for the reader — why an action was refused. */
    public string $message = '';

    /**
     * Every filter returns to the first page, and that includes the two that shipped
     * without doing so.
     *
     * Page 3 of everything is rarely page 3 of one endpoint or one week. A filtered list
     * that opens on an empty page reads as "nothing was delivered here" for an endpoint
     * that has plenty — the exact wrong answer from the one screen whose job is to say
     * what went out.
     */
    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingEventType(): void
    {
        $this->resetPage();
    }

    public function updatingEndpointId(): void
    {
        $this->resetPage();
    }

    public function updatingSubscriptionId(): void
    {
        $this->resetPage();
    }

    /**
     * Keep the deprecated alias and its replacement in step, in both directions.
     *
     * Assigning a property in PHP fires no Livewire hook, so neither of these can call the
     * other back. A published view binds one of the two names and never both, and whichever it
     * binds has to be the one the query reads.
     */
    public function updatedEndpointId(string $value): void
    {
        $this->subscriptionId = $value;
    }

    public function updatedSubscriptionId(string $value): void
    {
        $this->endpointId = $value;
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingUntil(): void
    {
        $this->resetPage();
    }

    /**
     * Replay one delivery. A disabled endpoint is refused here, where the reader can be
     * told why — the engine refuses it regardless, so this only decides whether they get
     * a message or an exception.
     */
    public function redeliver(string $id): void
    {
        $this->authorizeAction('redeliver');

        $this->message = '';

        // Same partition-key bound the table above already carries, on the single-row lookup
        // the redeliver action makes. Without it this one read visits every partition.
        $delivery = WebhookDelivery::query()->withinRetention()->findOrFail($id);

        if (! $delivery->subscription->is_active) {
            $this->message = __('webhooks::management.messages.endpoint_disabled');

            return;
        }

        Webhooks::redeliver($delivery);
    }

    /**
     * Test-ping one endpoint. Over its allowance the reader is told when to try again
     * rather than met with an exception — the same courtesy the disabled-endpoint case
     * above gets, and for the same reason: this is a screen, and both refusals are
     * ordinary outcomes of pressing the button rather than faults.
     */
    public function ping(int $subscriptionId): void
    {
        $this->authorizeAction('ping');

        $this->message = '';

        $subscription = WebhookSubscription::query()->findOrFail($subscriptionId);

        try {
            Webhooks::ping($subscription);
        } catch (TestPingThrottled $throttled) {
            $this->message = __('webhooks::management.messages.ping_throttled', [
                'seconds' => $throttled->secondsUntilAvailable,
            ]);
        }
    }

    /**
     * Page the log with the package's own pagination control rather than Livewire's
     * built-in one, whose markup paints a raw color palette no design token reaches and
     * whose landmark carries a hardcoded English accessible name. Publishing the views
     * (webhooks-views) publishes this control alongside them, so a host on another design
     * system restyles it in place.
     */
    public function paginationView(): string
    {
        return 'webhooks::pagination';
    }

    /**
     * And the same control for the SIMPLE paginator this component actually builds.
     *
     * Livewire resolves the two independently: paginationView() feeds
     * Paginator::defaultView, paginationSimpleView() feeds Paginator::defaultSimpleView,
     * and a simple paginator reads only the second. Overriding just the first left this
     * screen on livewire::simple-tailwind -- Livewire's own markup, with a hardcoded
     * English landmark and the raw palette the docblock above says is deliberately not
     * used. The package view carries both types.
     */
    public function paginationSimpleView(): string
    {
        return 'webhooks::pagination';
    }

    public function render(): View
    {
        // {@see CalendarDay} rather than a parse here: the same three defenses are needed by
        // the portal's own delivery panel, and a second copy of them is a second copy that
        // drifts. The upper bound is the start of the day after the one named, compared
        // strictly, so "up to the 20th" means the whole 20th.
        $from = CalendarDay::start($this->from);
        $until = CalendarDay::endExclusive($this->until);

        $endpoints = WebhookSubscription::query()
            ->select(['id', 'name', 'url'])
            ->orderBy('id')
            // One more than are shown, purely to learn whether there ARE more.
            // One past the cap is what makes the truncation detectable. Asking for MORE than one
            // extra changes nothing observable: the flag compares against the cap and the view takes
            // exactly the cap, so only the +0 and the -1 readings are real.
            ->limit(self::ENDPOINT_OPTIONS + 1)
            ->get();

        $truncated = $endpoints->count() > self::ENDPOINT_OPTIONS;

        $query = WebhookDelivery::query()
            // Eagerly, and with three columns rather than the row: every rendered delivery names
            // its endpoint now -- in the column and in both action names -- so the lazy read this
            // view has always done in its aria-labels was already 25 queries a page. It simply
            // cost nothing visible, which is why nobody counted it.
            ->with(['subscription:id,name,url'])
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->eventType !== '', fn (Builder $query): Builder => $query->where('event_type', $this->eventType))
            // The cast is for the declared column type, not for the comparison: ctype_digit has
            // already established the string is all digits, and the driver compares a numeric
            // string against a bigint the same way either side of it.
            ->when(ctype_digit($this->endpointId), fn (Builder $query): Builder => $query->where('subscription_id', (int) $this->endpointId));

        // Through the package's own timestamp scopes, and NOT through whereDate() or a bare
        // where(). Two separate reasons, both silent when got wrong:
        //
        // whereDate() wraps the column in a function, which is exactly what stops the planner
        // pruning partitions — webhook_deliveries is range-partitioned on created_at monthly,
        // so a date filter written the obvious way reads every partition to answer a question
        // about one month.
        //
        // And a bare where() binds a NAIVE literal, which PostgreSQL resolves against the
        // database SESSION zone. Measured while building this: with the session on +01, a
        // delivery stored at 23:59 UTC on the 20th vanished from a window whose upper bound
        // was the 20th. No error, no warning, an answer off by the offset.
        // {@see \Pushery\Webhooks\Database\Concerns\ScopesByTimestamp} binds it per dialect instead.
        if ($from instanceof CarbonInterface) {
            $query->createdAfter($from);
        }

        if ($until instanceof CarbonInterface) {
            $query->createdBefore($until);
        }

        $deliveries = $query
            ->latest('created_at')
            // A tiebreaker rather than a second sort preference. The column above ties — a fan-out
            // writes a burst of deliveries inside one second, and `created_at` has second
            // resolution — and a paginated read is several queries. Where the order is not total
            // the database may return tied rows differently per query, so a reader paging through a
            // burst sees rows twice and never sees others, with every individual page correct. The
            // primary key is unique, which is what makes the order total.
            ->orderByDesc('id')
            // simplePaginate, not paginate: this operator stub is unscoped over the whole
            // delivery log, and a full count(*) on every render does not scale on a partitioned
            // table with millions of rows. Prev/next navigation needs no total.
            //
            // That choice is also why this screen has no counterpart to the portal's
            // "land a reader who ran past the end on the last page that exists": that recovery
            // reads total() and lastPage(), and a simple paginator has neither — knowing them
            // is precisely the count this call refuses to pay. An operator who pages past a
            // tail the retention window dropped therefore gets an empty page and steps back,
            // rather than a count(*) over millions of rows on every render for everyone else.
            ->simplePaginate(25);

        // The catalog, or an empty list when the application declares none. Empty is the
        // load-bearing value rather than a missing one: a host that keeps the catalog empty
        // registers any type it likes, so the filter has to stay free text there. Only a
        // populated catalog becomes a choice -- {@see Settings::acceptedEventTypes()} states
        // the same distinction for the registration side.
        $eventTypes = new Settings()->eventTypes();

        return ViewFactory::make(UiVariant::view('delivery-log'), [
            'deliveries' => $deliveries,
            'endpoints' => $endpoints->take(self::ENDPOINT_OPTIONS),
            'endpointsTruncated' => $truncated,
            'eventTypes' => $eventTypes,
            // Decided here and passed in, rather than asked as `$this->canAction()` from the
            // markup. The two stubs are rendered by the package's own tests through
            // `View::make()` with a plain data array -- there is no component instance there, so
            // a view that reached for `$this` would work under Livewire and throw under the
            // suite that proves the stub compiles at all. Every other decision this view needs
            // already arrives the same way.
            'canRedeliver' => $this->canAction('redeliver'),
            'canPing' => $this->canAction('ping'),
        ]);
    }
}
