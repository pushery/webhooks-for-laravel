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
 * The busiest event types in the window, ranked by delivery count — a live read
 * over the raw rows, so it needs no rollup refresh.
 */
#[Lazy]
final class TopEvents extends Component
{
    use InteractsWithDashboard;

    public string $window = '24h';

    public int $limit = 5;

    /**
     * @return Collection<int, stdClass>
     */
    #[Computed]
    public function events(): Collection
    {
        return $this->metricsFor($this->window)->topEvents($this->limit);
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.panel');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.top-events');
    }
}
