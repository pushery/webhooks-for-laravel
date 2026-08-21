<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Exceptions\TestPingThrottled;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Livewire\Concerns\AuthorizesOperatorActions;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;

/**
 * The OPERATOR view of the delivery log: browse every delivery, filter it, and replay or
 * test-ping one. A published stub — restyle it and make it yours.
 *
 * It is deliberately UNSCOPED, and unauthorized by default: it reads EVERY tenant's
 * deliveries, so it MUST be embedded behind an operator-only gate of your own. It is not a
 * tenant-facing surface.
 *
 * Its two mutating actions — redeliver() and ping() — additionally honor
 * webhooks.admin.ability (or an overridden authorizeAction()) when a host sets one, so the
 * whole console gates the same way rather than only half of it. Left unset, nothing changes.
 *
 * The tenant-facing surface is the observability dashboard
 * (`Pushery\Webhooks\Dashboard\Livewire\DeliveriesTable`), which is owner-scoped and
 * policy-guarded.
 */
final class DeliveryLog extends Component
{
    use AuthorizesOperatorActions;
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
        $this->authorizeAction('redeliver');

        $this->message = '';

        $delivery = WebhookDelivery::query()->findOrFail($id);

        if (! $delivery->subscription->is_active) {
            $this->message = __('webhooks::management.messages.endpoint_disabled');

            return;
        }

        Webhooks::redeliver($delivery);
    }

    /**
     * Test-ping one endpoint. Over its allowance the reader is told when to try again
     * rather than met with an exception — the same courtesy the disabled-endpoint case
     * above gets, and for the same reason: this is a screen, and both refusals are
     * ordinary outcomes of pressing the button rather than faults.
     */
    public function ping(int $subscriptionId): void
    {
        $this->authorizeAction('ping');

        $this->message = '';

        $subscription = WebhookSubscription::query()->findOrFail($subscriptionId);

        try {
            Webhooks::ping($subscription);
        } catch (TestPingThrottled $throttled) {
            $this->message = __('webhooks::management.messages.ping_throttled', [
                'seconds' => $throttled->secondsUntilAvailable,
            ]);
        }
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
            // simplePaginate, not paginate: this operator stub is unscoped over the whole
            // delivery log, and a full count(*) on every render does not scale on a partitioned
            // table with millions of rows. Prev/next navigation needs no total.
            //
            // That choice is also why this screen has no counterpart to the portal's
            // "land a reader who ran past the end on the last page that exists": that recovery
            // reads total() and lastPage(), and a simple paginator has neither — knowing them
            // is precisely the count this call refuses to pay. An operator who pages past a
            // tail the retention window dropped therefore gets an empty page and steps back,
            // rather than a count(*) over millions of rows on every render for everyone else.
            ->simplePaginate(25);

        return ViewFactory::make('webhooks::livewire.delivery-log', [
            'deliveries' => $deliveries,
        ]);
    }
}
