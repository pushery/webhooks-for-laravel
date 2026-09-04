<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Pushery\Webhooks\Dashboard\Data\KpiSet;
use Pushery\Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;

/**
 * The KPI ribbon: total sent, delivered, failed, pending and retry rate for the
 * selected window. Lazy with a skeleton placeholder and polls on its own cadence so
 * a count refresh never re-renders the heavier chart alongside it.
 *
 * `isolate: false` bundles this panel's lazy load with its siblings' into ONE request. Six
 * isolated ones each morph the shared parent, and on a window switch four of them arrive
 * while the components they name are being replaced — which is the `__lazyLoad not found`
 * race. See {@see WebhooksDashboardPage} for the
 * whole chain, including why taking the window out of the keys would be the worse answer.
 */
#[Lazy(isolate: false)]
final class KpiCards extends Component
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

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.kpis');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.kpi-cards');
    }
}
