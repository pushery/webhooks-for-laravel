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
use Webhooks\Dashboard\PayloadVisibility;
use Webhooks\Models\WebhookDelivery;

/**
 * The slide-in detail drawer for one delivery: payload, the attempt count and a
 * replay action. Opened by a 'show-delivery' event from the deliveries table and
 * always tenant-scoped, so a foreign delivery id simply resolves to nothing.
 *
 * The body is gated separately from the rest of the drawer — reading that a delivery
 * failed and reading whose order it was are different permission levels. See
 * {@see PayloadVisibility}.
 *
 * Everything below the id is a COMPUTED property, and deliberately so: Livewire
 * serializes public properties into its snapshot but not computed ones, so neither the
 * delivery nor its body ever travels to the browser. Promoting any of them to a public
 * property for convenience would put the payload back on the wire and quietly turn the
 * view-level gate into decoration.
 *
 * @property-read WebhookDelivery|null $delivery
 * @property-read string $payloadMode
 * @property-read string|null $payloadNotice
 * @property-read string|null $payloadJson
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

    /**
     * How much of the body this user may see — see {@see PayloadVisibility}.
     */
    #[Computed]
    public function payloadMode(): string
    {
        return PayloadVisibility::current();
    }

    /**
     * The line explaining why the values are missing, or null when the body is shown in full.
     *
     * The view asks for this rather than comparing modes itself, so a published drawer view
     * never names an internal class — and a host that publishes it keeps working if the
     * mechanism behind the decision is reshaped.
     */
    #[Computed]
    public function payloadNotice(): ?string
    {
        return match ($this->payloadMode) {
            PayloadVisibility::MODE_FULL => null,
            PayloadVisibility::MODE_HIDDEN => $this->line('webhooks::dashboard.drawer.payload_hidden'),
            default => $this->line('webhooks::dashboard.drawer.payload_redacted'),
        };
    }

    /**
     * One translated line, narrowed to a string.
     *
     * A translation lookup is typed as string|array|null because a key can resolve to a
     * group. A host that publishes the language files could make this one an array by
     * accident, and Blade would then render nothing — costing the drawer exactly the
     * explanation this guard depends on for not looking like a defect. Falling back to the
     * key keeps something visible and points at the cause.
     */
    private function line(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }

    /**
     * The body as it should be rendered, or null when nothing is open or the mode hides it.
     *
     * Computed on purpose, like {@see self::delivery()}, and that is load-bearing rather than
     * stylistic: a computed property is not serialized into Livewire's snapshot, so a body the
     * user may not see never travels to the browser at all. Were this a public property, the
     * full payload would ride along with every request and the check in the view would be a
     * curtain rather than a boundary.
     */
    #[Computed]
    public function payloadJson(): ?string
    {
        $delivery = $this->delivery;

        if ($delivery === null || $this->payloadMode === PayloadVisibility::MODE_HIDDEN) {
            return null;
        }

        $payload = $this->payloadMode === PayloadVisibility::MODE_FULL
            ? $delivery->payload
            : PayloadVisibility::redact($delivery->payload);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.delivery-detail-drawer');
    }
}
