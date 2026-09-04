<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
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

    /**
     * The window this panel counts over. LOCKED because it is a mount parameter and nothing else — no `wire:model` binds it, and a public
     * Livewire property that nothing binds is still client input. The page decides the
     * window, screens it against the configured set, and remounts every panel on it through
     * the wire:key; a panel that also accepted the value from the browser re-opened the hole
     * the page had just closed, one component deeper, where nothing screens it at all.
     *
     * The failure is not silent: WindowResolver throws on a token it does not know, so an
     * unscreened value is a 500 where the panel should be.
     */
    #[Locked]
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
        // Both fallbacks below are absorbed by the floor on the last line: `max(1.0, …)` takes
        // anything at or below 1, so 0 and 1 and -1 all come out of this method as 1.0.
        // Measured: `?? 0` changed to `?? 1` and `return 0.0` to `1.0`, the dashboard suites
        // green for both.
        //
        // The control is the same method: a row carrying a real p95 is still reported as itself
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
