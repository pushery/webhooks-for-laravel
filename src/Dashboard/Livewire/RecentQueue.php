<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;
use Webhooks\Models\WebhookDelivery;

/**
 * The live "recent queue" strip: the newest deliveries for the acting tenant with a
 * status badge and an inline replay action. A live read (not the rollup), polled on
 * the panel cadence, so an operator watches deliveries land in near real time.
 */
#[Lazy]
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
