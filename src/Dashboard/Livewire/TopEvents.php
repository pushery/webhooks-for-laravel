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
 *
 * `isolate: false` bundles this panel's lazy load with its siblings' into ONE request. Six
 * isolated ones each morph the shared parent, and on a window switch four of them arrive
 * while the components they name are being replaced — which is the `__lazyLoad not found`
 * race. See {@see WebhooksDashboardPage} for the
 * whole chain, including why taking the window out of the keys would be the worse answer.
 */
#[Lazy(isolate: false)]
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
