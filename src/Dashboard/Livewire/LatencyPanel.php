<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use stdClass;
use Webhooks\Dashboard\Data\KpiSet;
use Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;

/**
 * The latency panel: the window-level P50/P90/P95/P99 (computed live over the raw
 * rows, never averaged from the rollup) plus the per-hour p50/p95 trend from the
 * rollup, shown as a compact token-styled sparkline of bars.
 */
#[Lazy]
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
        $values = $this->trend()
            ->map(static function (stdClass $row): float {
                $p95 = $row->p95 ?? 0;

                return is_numeric($p95) ? (float) $p95 : 0.0;
            })
            ->all();

        return $values === [] ? 1.0 : max(1.0, ...$values);
    }

    #[On('dashboard-window-changed')]
    public function changeWindow(string $window): void
    {
        $this->window = $window;
        unset($this->metrics, $this->trend, $this->peakLatency);
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
