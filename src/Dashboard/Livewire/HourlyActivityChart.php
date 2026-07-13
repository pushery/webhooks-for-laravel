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
use Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;

/**
 * The stacked hourly-activity panel: delivered / pending / failed per hour across
 * the window. Renders as token-styled stacked bars (the documented plain-Blade
 * escape hatch) rather than the JS chart adapter, so it draws with no compiled
 * asset bundle and stays fully server-renderable and testable.
 */
#[Lazy]
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

                return is_numeric($total) ? (int) $total : 0;
            })
            ->all();

        return $totals === [] ? 1 : max(1, ...$totals);
    }

    #[On('dashboard-window-changed')]
    public function changeWindow(string $window): void
    {
        $this->window = $window;
        unset($this->hourly, $this->peak);
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
