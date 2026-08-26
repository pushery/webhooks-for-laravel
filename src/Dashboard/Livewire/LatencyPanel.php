<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Pushery\Webhooks\Dashboard\Data\KpiSet;
use Pushery\Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;
use stdClass;

/**
 * The latency panel: the window-level P50/P90/P95/P99 (computed live over the raw
 * rows, never averaged from the rollup) plus the per-hour p50/p95 trend from the
 * rollup, shown as a compact token-styled sparkline of bars.
 *
 * `isolate: false` bundles this panel's lazy load with its siblings' into ONE request. Six
 * isolated ones each morph the shared parent, and on a window switch four of them arrive
 * while the components they name are being replaced — which is the `__lazyLoad not found`
 * race. See {@see WebhooksDashboardPage} for the
 * whole chain, including why taking the window out of the keys would be the worse answer.
 */
#[Lazy(isolate: false)]
final class LatencyPanel extends Component
{
    use InteractsWithDashboard;

    public string $window = '24h';

    #[Computed]
    public function metrics(): KpiSet
    {
        return $this->metricsFor($this->window)->kpis();
    }

    /**
     * The per-hour p95 trend across the window, oldest first — the trend line only,
     * never a window percentile.
     *
     * @return Collection<int, stdClass>
     */
    #[Computed]
    public function trend(): Collection
    {
        return $this->metricsFor($this->window)->hourly();
    }

    /**
     * The tallest per-hour p95 in the window so the trend bars scale to it.
     */
    #[Computed]
    public function peakLatency(): float
    {
        // ⚠️ BOTH FALLBACKS BELOW ARE UNKILLABLE, and mutation testing reports four mutants on
        // them. The reason is the floor on the last line: `max(1.0, …)` absorbs anything at or
        // below 1, and the mutators only move a literal by one — so 0 and 1 and -1 all come out
        // of this method as 1.0. Measured: `?? 0` moved to `?? 1` and `return 0.0` to `1.0`, the
        // dashboard suites green for both.
        //
        // The control is the same method: a row carrying a REAL p95 is still reported as itself
        // (ChartPeakFallbackTest pins 250.5), so this is a statement about values under the
        // floor rather than about an unmeasured peak.
        //
        // They stay because the floor is a rendering decision — every bar divides by this — and
        // relying on it to also mean "no measurement" would put two jobs on one expression.
        $values = $this->trend()
            ->map(static function (stdClass $row): float {
                $p95 = $row->p95 ?? 0;

                if (is_numeric($p95)) {
                    return (float) $p95;
                }

                return 0.0;
            })
            ->all();

        return $values === [] ? 1.0 : max(1.0, ...$values);
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.panel');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.latency-panel');
    }
}
