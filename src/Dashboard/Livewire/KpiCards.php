<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Webhooks\Dashboard\Data\KpiSet;
use Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;

/**
 * The KPI ribbon: total sent, delivered, failed, pending and retry rate for the
 * selected window. Lazy with a skeleton placeholder and polls on its own cadence so
 * a count refresh never re-renders the heavier chart alongside it.
 */
#[Lazy]
final class KpiCards extends Component
{
    use InteractsWithDashboard;

    public string $window = '24h';

    /**
     * Cached for the request: the panel view reads it more than once. The ribbon shows
     * only counts (never latency percentiles), so it uses the counts-only query and
     * leaves the heavy window-level percentile sort to the latency panel.
     */
    #[Computed]
    public function metrics(): KpiSet
    {
        return $this->metricsFor($this->window)->counts();
    }

    #[On('dashboard-window-changed')]
    public function changeWindow(string $window): void
    {
        $this->window = $window;
        unset($this->metrics);
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.kpis');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.kpi-cards');
    }
}
