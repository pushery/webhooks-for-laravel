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

    /**
     * The window this panel counts over. LOCKED for the reason $limit is locked beside it:
     * it is a mount parameter and nothing else — no `wire:model` binds it, and a public
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
     * The row budget. LOCKED because it is a mount parameter and nothing else: no
     * `wire:model` binds it, and a public Livewire property that nothing binds is still
     * client input — Livewire applies an update for any public property declared on the
     * subclass, and a reader who set it to -1 got a query with no LIMIT clause at all,
     * repeated every poll interval by a tab left open.
     */
    #[Locked]
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
