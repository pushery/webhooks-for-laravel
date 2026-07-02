<?php

declare(strict_types=1);

namespace Webhooks\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Component;
use Livewire\WithPagination;
use Webhooks\Facades\Webhooks;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;

/**
 * Browse the delivery log, filter it, and replay or test-ping deliveries. A
 * published stub — restyle it and place it behind your own authorization.
 */
final class DeliveryLog extends Component
{
    use WithPagination;

    public string $status = '';

    public string $eventType = '';

    public function redeliver(string $id): void
    {
        $delivery = WebhookDelivery::query()->findOrFail($id);

        Webhooks::redeliver($delivery);
    }

    public function ping(int $subscriptionId): void
    {
        $subscription = WebhookSubscription::query()->findOrFail($subscriptionId);

        Webhooks::ping($subscription);
    }

    public function render(): View
    {
        $deliveries = WebhookDelivery::query()
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->eventType !== '', fn (Builder $query): Builder => $query->where('event_type', $this->eventType))
            ->latest('created_at')
            ->paginate(25);

        return ViewFactory::make('webhooks::livewire.delivery-log', [
            'deliveries' => $deliveries,
        ]);
    }
}
