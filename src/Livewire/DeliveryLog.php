<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Exceptions\TestPingThrottled;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Livewire\Concerns\AuthorizesOperatorActions;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;

/**
 * The OPERATOR view of the delivery log: browse every delivery, filter it, and replay or
 * test-ping one. A published stub — restyle it and make it yours.
 *
 * It is deliberately UNSCOPED, and unauthorized by default: it reads EVERY tenant's
 * deliveries, so it MUST be embedded behind an operator-only gate of your own. It is not a
 * tenant-facing surface.
 *
 * Its two mutating actions — redeliver() and ping() — additionally honor
 * webhooks.admin.abilities and webhooks.admin.ability (or an overridden authorizeAction())
 * when a host sets one, so the whole console gates the same way rather than only half of it.
 * Left unset, nothing changes.
 *
 * ⚠️ Not `final`, and deliberately so: the override that sentence offers has to be reachable.
 * See {@see AuthorizesOperatorActions} — a spatie/laravel-permission name in the SINGLE
 * ability key denies every action silently, because the action name travels positionally and
 * that package's Gate::before hook takes the first positional argument for a guard. The map
 * passes no argument at all and is the direct way past it; the subclass remains the way past
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

    public string $status = '';

    public string $eventType = '';

    /**
     * One endpoint's id, or '' for every endpoint. A string rather than an int because it
     * is bound to a <select> whose empty option carries '' — and because every public
     * property here is writable from the browser, so it holds whatever arrives.
     */
    public string $subscriptionId = '';

    /**
     * Inclusive window bounds as 'Y-m-d', or '' for unbounded. Read in the application's
     * timezone: this stub is the operator's own console, not the tenant-facing dashboard,
     * so it has no business resolving a per-tenant zone.
     */
    public string $from = '';

    public string $until = '';

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

    public function updatingSubscriptionId(): void
    {
        $this->resetPage();
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

        $delivery = WebhookDelivery::query()->findOrFail($id);

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
     * The start of the day a bound names, or null when the value is absent or not a date.
     *
     * Both bounds are public properties, so they carry whatever the browser sends —
     * including the half-typed dates `wire:model.live` delivers between keystrokes
     * ('2026-0' on the way to '2026-06-20'). A filter that threw on those would turn typing
     * into an error page, so an unreadable value is simply not a bound.
     *
     * The round-trip comparison is what rejects '2026-13-45': Carbon rolls an out-of-range
     * part forward into a real date rather than refusing it, so the parse alone would answer
     * a question the reader did not ask.
     */
    private function dayStart(string $value): ?CarbonInterface
    {
        if ($value === '') {
            return null;
        }

        // The shape is checked BEFORE Carbon sees it, because Carbon THROWS on a string it
        // cannot read rather than returning false — measured on '2026-0', which is what
        // wire:model.live sends while a reader is still typing the year ("A four digit year
        // could not be found"). /D so a trailing newline cannot slip past the anchor.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = Date::createFromFormat('!Y-m-d', $value);

        // And the round-trip rejects what Carbon reads TOO willingly. Every well-shaped
        // string parses — measured: '2026-13-45' becomes 2027-02-14, '2026-02-30' becomes
        // 2026-03-02, '0000-00-00' becomes -0001-11-30 — so the parse alone would answer a
        // question the reader did not ask. Comparing the result back against the input is
        // what turns "parsed" into "meant".
        if (! $date instanceof CarbonInterface || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    public function render(): View
    {
        $from = $this->dayStart($this->from);
        // The upper bound is the START OF THE DAY AFTER the one named, compared strictly.
        // A reader asking for "up to the 20th" means the whole 20th, and the half-open form
        // says that without arguing about how many decimals a timestamp has.
        $until = $this->dayStart($this->until)?->addDay();

        $endpoints = WebhookSubscription::query()
            ->select(['id', 'name', 'url'])
            ->orderBy('id')
            // One more than are shown, purely to learn whether there ARE more.
            ->limit(self::ENDPOINT_OPTIONS + 1)
            ->get();

        $truncated = $endpoints->count() > self::ENDPOINT_OPTIONS;

        $query = WebhookDelivery::query()
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->eventType !== '', fn (Builder $query): Builder => $query->where('event_type', $this->eventType))
            ->when(ctype_digit($this->subscriptionId), fn (Builder $query): Builder => $query->where('subscription_id', (int) $this->subscriptionId));

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
        // {@see ScopesByTimestamp} binds it per dialect instead.
        if ($from instanceof CarbonInterface) {
            $query->createdAfter($from);
        }

        if ($until instanceof CarbonInterface) {
            $query->createdBefore($until);
        }

        $deliveries = $query
            ->latest('created_at')
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

        return ViewFactory::make('webhooks::livewire.delivery-log', [
            'deliveries' => $deliveries,
            'endpoints' => $endpoints->take(self::ENDPOINT_OPTIONS),
            'endpointsTruncated' => $truncated,
        ]);
    }
}
