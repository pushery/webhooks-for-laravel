<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Pushery\Webhooks\Database\Dialect\Sql\NullsLastOrder;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Health\EndpointHealth;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\Platform\Support\PortalRoutes;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * A status board of the tenant's own endpoints and their health at a glance: one row
 * per endpoint with its cached health band + score, and — once recomputed — its live
 * success rate, p95 latency and sample size. The fast view reads only the cached
 * health columns, so opening the board never fans out a per-endpoint history query.
 *
 * A Recompute action (per row, or for every endpoint at once) drives the shared health
 * engine to score the endpoint from its recent delivery history and persist the fresh
 * score onto the cached columns — the same path the scheduled command uses, so the
 * scoring math lives in one place. Every query is owner-scoped and every action is
 * policy-authorized, so a tenant only ever sees and recomputes the endpoints it owns.
 */
#[Layout('webhooks::self-service.layout')]
final class EndpointHealthMatrix extends Component
{
    use InteractsWithEndpoints;

    /**
     * The most rows one board draws, and therefore the most endpoints one recompute touches.
     *
     * There was no ceiling here at all: render() read every endpoint the tenant owns, and
     * `max_endpoints_per_tenant` ships as null, so a board could be arbitrarily long and a
     * recompute cost two synchronous queries for each of its rows. The same cap in the same shape
     * as the operator console's endpoint filter, for the same reason — and, like it, when it
     * truncates the view says so. A short list that looks complete is how a reader concludes an
     * endpoint is gone.
     */
    private const int MAX_ROWS = 200;

    /** A message for the reader — why an action was refused, or what was left out. */
    public string $message = '';

    /** The cached column a row is sorted by: 'score' or 'status'. */
    public string $sortField = 'score';

    /** The active sort direction: 'asc' or 'desc'. */
    public string $sortDirection = 'desc';

    /**
     * The freshly computed live metrics per endpoint id, populated by a Recompute.
     * These are not cached on the subscription, so they show only after a recompute.
     *
     * @var array<int, array{successRate: float, p95: float, sampleSize: int}>
     */
    public array $reports = [];

    /**
     * Toggle or switch the sort column. An unknown field is ignored, so the control
     * can never sort by a column that is not a cached, orderable one.
     */
    public function sortBy(string $field): void
    {
        if (! array_key_exists($field, $this->sortColumns())) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'desc';
    }

    /**
     * Recompute and persist the health of one owned endpoint from its recent history,
     * then surface the live metrics for its row. Authorized against the row-level
     * policy, so recomputing a foreign endpoint is refused.
     */
    public function recompute(int $id): void
    {
        // Scope at the query, not only at the policy: a foreign (or tampered) id resolves to
        // nothing and fails not-found before any action runs — the row-level policy below is the
        // second, defense-in-depth guard, not the only one.
        $subscription = $this->findOwnedEndpoint($id);
        // This authorize() call cannot be the sole refusal — InteractsWithEndpoints spells out why
        // in full: the boot gate reads the same ability, and findOwnedEndpoint() has already
        // enforced the ownership this policy would add. That docblock ends "Do not 'kill' them by
        // deleting them", and this is one of the five it means.
        $this->authorize('update', $subscription);

        $this->refreshRow($subscription);

        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.health_recomputed'));
    }

    /**
     * Recompute every endpoint the tenant owns in one pass. Scoped, so it can only ever
     * touch the acting tenant's own endpoints.
     */
    public function recomputeAll(): void
    {
        // The brake the other three tenant actions have had all along. This one is the most
        // expensive of them by a wide margin — two queries per endpoint, synchronously, in the
        // web request — and it was the one with nothing in front of it. The button is only
        // disabled by wire:loading, so a second press starts the whole pass again.
        if ($this->recomputeRateExceeded()) {
            $this->message = __('webhooks::self-service.health_page.recompute_throttled');

            return;
        }

        $this->message = '';

        foreach ($this->boardQuery()->get() as $subscription) {
            $this->refreshRow($subscription);
        }

        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.health_recomputed_all'));
    }

    /**
     * The tenant's endpoints in board order, bounded.
     *
     * One query shape for the board and for the recompute, so the pass can never touch a row
     * the reader is not looking at — and can never be longer than the board either.
     *
     * @return Builder<WebhookSubscription>
     */
    private function boardQuery(): Builder
    {
        return $this->scopedQuery()
            ->orderByRaw($this->orderClause())
            ->orderBy('id')
            ->limit(self::MAX_ROWS + 1);
    }

    /**
     * Drive the shared engine to score-and-persist one endpoint, and remember its live
     * metrics for the current view. Reuses the engine's own refresh path, so the
     * scoring and persistence logic is never duplicated here.
     */
    private function refreshRow(WebhookSubscription $subscription): void
    {
        $report = Container::getInstance()->make(EndpointHealth::class)->refresh($subscription);

        // The (int) cast changes nothing: the id is an int already, and PHP normalizes a numeric
        // array key regardless. It is kept because the array is documented as keyed by the endpoint
        // id, and the view looks a row up by exactly that.
        $this->reports[(int) $subscription->id] = [
            'successRate' => $report->successRate,
            'p95' => $report->p95,
            'sampleSize' => $report->sampleSize,
        ];
    }

    /**
     * The sortable columns mapped to their cached database column.
     *
     * @return array<string, string>
     */
    private function sortColumns(): array
    {
        return ['score' => 'health_score', 'status' => 'health_status'];
    }

    /**
     * The ORDER BY clause for the current sort, as a literal string so the raw order
     * can never carry anything but one of these four fixed, safe expressions. A null
     * cached score/status (no history yet) always sorts to the end, so the Unknown
     * endpoints never crowd the top of the board.
     *
     * @return literal-string
     */
    private function orderClause(): string
    {
        $column = $this->sortField === 'status' ? 'health_status' : 'health_score';

        return NullsLastOrder::by(WebhookConnection::dialect(), $column, $this->sortDirection === 'asc');
    }

    public function render(): View
    {
        $endpoints = $this->boardQuery()->get();

        // One row beyond the cap is asked for so the truncation can be DETECTED, then dropped.
        $truncated = $endpoints->count() > self::MAX_ROWS;
        $endpoints = $endpoints->take(self::MAX_ROWS);

        // Three of the entries below are redundant: `reports`, `sortField` and `sortDirection` are
        // public properties of this component, and Livewire hands every public property to the view
        // already. Removing them changes nothing, measured one at a time with the suite green.
        //
        // They stay because the view reads them by those names and this list is where a reader
        // looks to see what it is given. `endpoints` and `portalUrl` are not redundant: neither is
        // a property, and the view has no other source for them.
        //
        // What must not be concluded from this note is that the three properties are unused. It is
        // the opposite: they are used, and by the view, which is why they are public.
        return ViewFactory::make('webhooks::self-service.livewire.endpoint-health-matrix', [
            'endpoints' => $endpoints,
            'endpointsTruncated' => $truncated,
            'reports' => $this->reports,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
            // Null when the portal's own pages are not mounted: there is nowhere to go back to.
            'portalUrl' => PortalRoutes::url('webhooks.self-service'),
        ]);
    }
}
