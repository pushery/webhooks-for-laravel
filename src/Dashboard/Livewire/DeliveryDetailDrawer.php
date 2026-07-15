<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Webhooks\Dashboard\DashboardScope;
use Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;
use Webhooks\Models\WebhookDelivery;

/**
 * The slide-in detail drawer for one delivery: payload, the attempt count and a
 * replay action. Opened by a 'show-delivery' event from the deliveries table and
 * always tenant-scoped, so a foreign delivery id simply resolves to nothing.
 */
final class DeliveryDetailDrawer extends Component
{
    use InteractsWithDashboard;

    public ?string $deliveryId = null;

    #[On('show-delivery')]
    public function show(string $deliveryId): void
    {
        $this->deliveryId = $deliveryId;
        unset($this->delivery);
    }

    public function close(): void
    {
        $this->deliveryId = null;
        unset($this->delivery);
    }

    /**
     * The selected delivery for the acting tenant, or null when nothing is open or
     * the id belongs to another tenant.
     */
    #[Computed]
    public function delivery(): ?WebhookDelivery
    {
        if ($this->deliveryId === null) {
            return null;
        }

        [$ownerSql, $ownerBindings] = DashboardScope::current()->condition();

        return $this->sourceModel()
            ->newQuery()
            ->whereRaw($ownerSql, $ownerBindings)
            ->find($this->deliveryId);
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.delivery-detail-drawer');
    }
}
