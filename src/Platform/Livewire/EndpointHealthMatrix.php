<?php

declare(strict_types=1);

namespace Webhooks\Platform\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Health\EndpointHealth;
use Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;

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

    public function mount(): void
    {
        $this->authorize('manage-webhook-endpoints');
    }

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
        $subscription = WebhookSubscription::query()->findOrFail($id);
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
        $this->authorize('manage-webhook-endpoints');

        foreach ($this->scopedQuery()->get() as $subscription) {
            $this->refreshRow($subscription);
        }

        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.health_recomputed_all'));
    }

    /**
     * Drive the shared engine to score-and-persist one endpoint, and remember its live
     * metrics for the current view. Reuses the engine's own refresh path, so the
     * scoring and persistence logic is never duplicated here.
     */
    private function refreshRow(WebhookSubscription $subscription): void
    {
        $report = Container::getInstance()->make(EndpointHealth::class)->refresh($subscription);

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
        $ascending = $this->sortDirection === 'asc';

        return match (true) {
            $this->sortField === 'status' && $ascending => 'health_status asc nulls last',
            $this->sortField === 'status' => 'health_status desc nulls last',
            $ascending => 'health_score asc nulls last',
            default => 'health_score desc nulls last',
        };
    }

    public function render(): View
    {
        $endpoints = $this->scopedQuery()
            ->orderByRaw($this->orderClause())
            ->orderBy('id')
            ->get();

        return ViewFactory::make('webhooks::self-service.livewire.endpoint-health-matrix', [
            'endpoints' => $endpoints,
            'reports' => $this->reports,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ]);
    }
}
