<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Pushery\Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;
use stdClass;

/**
 * The stacked hourly-activity panel: delivered / pending / failed per hour across
 * the window. Renders as token-styled stacked bars (the documented plain-Blade
 * escape hatch) rather than the JS chart adapter, so it draws with no compiled
 * asset bundle and stays fully server-renderable and testable.
 *
 * `isolate: false` bundles this panel's lazy load with its siblings' into ONE request. Six
 * isolated ones each morph the shared parent, and on a window switch four of them arrive
 * while the components they name are being replaced — which is the `__lazyLoad not found`
 * race. See {@see WebhooksDashboardPage} for the
 * whole chain, including why taking the window out of the keys would be the worse answer.
 */
#[Lazy(isolate: false)]
final class HourlyActivityChart extends Component
{
    use InteractsWithDashboard;

    public string $window = '24h';

    /**
     * The hourly rollup rows in the window, oldest first.
     *
     * @return Collection<int, stdClass>
     */
    #[Computed]
    public function hourly(): Collection
    {
        return $this->metricsFor($this->window)->hourly();
    }

    /**
     * The tallest hourly total in the window, so each bar can be sized as a share
     * of it. At least one, so an all-zero window never divides by zero.
     */
    #[Computed]
    public function peak(): int
    {
        $totals = $this->hourly()
            ->map(static function (stdClass $row): int {
                $total = $row->total;

                if (is_numeric($total)) {
                    return (int) $total;
                }

                // EQUIVALENT, and reported every run: the fallback is swallowed by the
                // `max(1, ...)` below, so 0, 1 and -1 all produce the same peak. It stays 0
                // because that is what a row with no readable total contributed — reporting a
                // 1 would be inventing a delivery.
                return 0;
            })
            ->all();

        return $totals === [] ? 1 : max(1, ...$totals);
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.chart');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.hourly-activity-chart');
    }
}
