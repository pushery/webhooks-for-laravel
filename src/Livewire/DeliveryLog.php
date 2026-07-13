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
 * The OPERATOR view of the delivery log: browse every delivery, filter it, and replay or
 * test-ping one. A published stub — restyle it and make it yours.
 *
 * It is deliberately UNSCOPED and UNAUTHORIZED: it reads EVERY tenant's deliveries, so
 * it MUST be embedded behind an operator-only gate of your own. It is not a
 * tenant-facing surface.
 *
 * The tenant-facing surface is the observability dashboard
 * (`Webhooks\Dashboard\Livewire\DeliveriesTable`), which is owner-scoped and
 * policy-guarded.
 */
final class DeliveryLog extends Component
{
    use WithPagination;

    public string $status = '';

    public string $eventType = '';

    /** A message for the reader — why an action was refused. */
    public string $message = '';

    /**
     * Replay one delivery. A disabled endpoint is refused here, where the reader can be
     * told why — the engine refuses it regardless, so this only decides whether they get
     * a message or an exception.
     */
    public function redeliver(string $id): void
    {
        $this->message = '';

        $delivery = WebhookDelivery::query()->findOrFail($id);

        if (! $delivery->subscription->is_active) {
            $this->message = __('webhooks::management.messages.endpoint_disabled');

            return;
        }

        Webhooks::redeliver($delivery);
    }

    public function ping(int $subscriptionId): void
    {
        $subscription = WebhookSubscription::query()->findOrFail($subscriptionId);

        Webhooks::ping($subscription);
    }

    /**
     * Page the log with the package's own pagination control rather than Livewire's
     * built-in one, whose markup paints a raw color palette no design token reaches and
     * whose landmark carries a hardcoded English accessible name. Publishing the views
     * (webhooks-views) publishes this control alongside them, so a host on another design
     * system restyles it in place.
     */
    public function paginationView(): string
    {
        return 'webhooks::pagination';
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
