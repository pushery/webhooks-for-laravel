<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Pushery\Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;
use Pushery\Webhooks\Models\WebhookDelivery;

/**
 * The live "recent queue" strip: the newest deliveries for the acting tenant with a
 * status badge and an inline replay action. A live read (not the rollup), polled on
 * the panel cadence, so an operator watches deliveries land in near real time.
 *
 * `isolate: false` bundles this panel's lazy load with its siblings' into ONE request. Six
 * isolated ones each morph the shared parent, and on a window switch four of them arrive
 * while the components they name are being replaced — which is the `__lazyLoad not found`
 * race. See {@see WebhooksDashboardPage} for the
 * whole chain, including why taking the window out of the keys would be the worse answer.
 */
#[Lazy(isolate: false)]
final class RecentQueue extends Component
{
    use InteractsWithDashboard;

    public int $limit = 8;

    /**
     * @return Collection<int, WebhookDelivery>
     */
    #[Computed]
    public function deliveries(): Collection
    {
        return $this->metricsFor('24h')->recentQueue($this->limit);
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.table');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.recent-queue');
    }
}
