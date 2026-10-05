<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Pushery\Webhooks\Exceptions\TestPingThrottled;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\Platform\Support\PortalRoutes;
use Pushery\Webhooks\Support\UiVariant;

/**
 * The tenant's own endpoint list: each row shows the URL, an active toggle, a cached
 * health badge, its event-type summary and the test / reveal-secret / edit / delete actions.
 * Paginated and always scoped to the acting tenant, so a foreign owner's endpoints are
 * never listed. The "New endpoint" action is refused once the tenant hits its cap.
 *
 * Actions that mutate a single endpoint re-resolve it through the owner-scoped query
 * and re-authorize the row-level policy, so the list can never act on a row the tenant
 * does not own.
 *
 * @property-read string|null $capNotice
 */
#[Lazy]
final class EndpointList extends Component
{
    use InteractsWithEndpoints;
    use WithPagination;

    public int $perPage = 10;

    /**
     * Re-render when another panel changes the underlying endpoints, so the list stays
     * in step after a create, edit, delete or secret rotation.
     */
    #[On('endpoint-saved')]
    #[On('endpoint-deleted')]
    public function refreshList(): void
    {
        unset($this->capReached, $this->capNotice);
    }

    /**
     * Ask the form to open in create mode. Refused when the tenant is at its cap.
     */
    public function newEndpoint(): void
    {
        $this->authorize('create', WebhookSubscription::class);

        $refusal = $this->registrationRefusal(__('webhooks::self-service.limit_reached'));

        if ($refusal !== null) {
            $this->dispatch('wirekit-toast', variant: 'warning', message: $refusal);

            return;
        }

        $this->dispatch('new-endpoint');
    }

    /**
     * Ask the form to open in edit mode for one owned endpoint.
     */
    public function edit(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        // Redundant on both paths, and deliberately kept: {@see InteractsWithEndpoints} states the
        // rule and its measurement. On a loaded list the boot gate refuses first. On an unloaded
        // placeholder this action only dispatches an event, and the render gate refuses the
        // response that would carry it. This is what still refuses if a future caller reaches the
        // action another way.
        $this->authorize('update', $subscription);

        $this->dispatch('edit-endpoint', id: $subscription->id);
    }

    /**
     * Ask the secret panel to reveal the signing secret for one owned endpoint.
     */
    public function reveal(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        // Redundant on both paths, and deliberately kept: {@see InteractsWithEndpoints} states the
        // rule and its measurement. On a loaded list the boot gate refuses first. On an unloaded
        // placeholder this action only dispatches an event, and the render gate refuses the
        // response that would carry it. This is what still refuses if a future caller reaches the
        // action another way.
        $this->authorize('view', $subscription);

        $this->dispatch('reveal-secret', id: $subscription->id);
    }

    /**
     * Enable or disable one owned endpoint, through the manager — so the UI cannot drift
     * from what activation means (disabling stamps disabled_at; enabling clears it AND
     * the circuit-breaker streak, or the endpoint would re-disable on its next failure).
     */
    public function toggle(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        $this->authorize('update', $subscription);

        $subscription->is_active
            ? Webhooks::disable($subscription)
            : Webhooks::enable($subscription);

        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.endpoint_updated'));
    }

    /**
     * Send one test event to an owned endpoint, so a tenant can prove the destination
     * answers without waiting for a real one to happen. It is the only question the
     * portal could not answer before: until an actual product event fired, a freshly
     * registered endpoint told its owner nothing — and by then a failure is a lost
     * event rather than a test.
     *
     * A spent allowance is reported as a message, not thrown. This is a screen, and
     * running out of test events is an ordinary outcome of pressing the button rather
     * than a fault — the same call the operator console makes.
     */
    public function ping(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        // Redundant with the boot gate, and deliberately kept: {@see InteractsWithEndpoints}
        // states the rule and its measurement -- the gate reads the same ability this policy
        // consults, and the ownership it adds is already enforced by the scoped lookup. This is
        // what still refuses if a future caller reaches the action without that lookup.
        $this->authorize('update', $subscription);

        // A disabled endpoint accepts the ping, logs a delivery and is then dropped at
        // send time by the delivery gate. The tenant would read "sent" over nothing ever
        // arriving, which is worse than a refusal — so refuse here, where the reason can
        // still be given.
        if (! $subscription->is_active) {
            $this->dispatch('wirekit-toast', variant: 'warning', message: __('webhooks::self-service.toast.ping_disabled'));

            return;
        }

        try {
            Webhooks::ping($subscription);
        } catch (TestPingThrottled $throttled) {
            $this->dispatch('wirekit-toast', variant: 'warning', message: __('webhooks::self-service.toast.ping_throttled', [
                'seconds' => $throttled->secondsUntilAvailable,
            ]));

            return;
        }

        $this->dispatch('endpoint-pinged');
        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.ping_sent'));
    }

    /**
     * Permanently remove one owned endpoint, gated by both the row-level policy and the
     * allow_delete switch (the policy already honors the switch).
     *
     * Not named `delete`, so the button works under a strict CSP on every Livewire 4 release.
     * Livewire's CSP-safe build rewrites `wire:click="delete(1)"` to `$wire.delete(1)` and
     * evaluates it with the Alpine parser it bundles. Up to Livewire 4.4.4 that parser refuses
     * a keyword after the dot, so the button renders and does nothing: no error, no log, and an
     * operator who clicks it concludes the endpoint is gone. Livewire 4.4.5 is the first release
     * whose parser accepts it.
     */
    public function destroy(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        $this->authorize('delete', $subscription);

        $subscription->delete();

        $this->resetPage();
        $this->dispatch('endpoint-deleted');
        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.endpoint_deleted'));
    }

    /**
     * The pre-2.0.0 name, kept so a view published before the rename keeps working. Under a
     * strict CSP that published copy reaches this method from Livewire 4.4.5 on. On 4.4.4 and
     * older the CSP-safe build refuses `$wire.delete(1)`, the form it rewrites
     * `wire:click="delete(1)"` into, and the button does nothing; re-publish the view, or change
     * that one line, to get it back there.
     *
     * Deliberately NOT tagged `@deprecated`, and that is not an oversight. On PHP 8.4 the
     * code-style pass rewrites that tag into `#[\Deprecated]`, which raises E_USER_DEPRECATED
     * on every call — and a host that runs PHPUnit with `failOnDeprecation` would then have
     * this shim break the very tests it exists to keep working. A compatibility forwarder
     * that fails the people who have not migrated yet is worse than no forwarder.
     */
    public function delete(int $id): void
    {
        $this->destroy($id);
    }

    /**
     * Whether the tenant may not register another endpoint, so the "New endpoint" action is
     * hidden. Cached for the request; refreshList() clears it when an endpoint is saved or
     * deleted.
     */
    #[Computed]
    public function capReached(): bool
    {
        return $this->capNotice !== null;
    }

    /**
     * The line the list shows in place of the "New endpoint" action, or null while the tenant may
     * register one: the portal's short label for the configured cap, and for a host limit that
     * names no sentence of its own, the host's sentence where it names one.
     */
    #[Computed]
    public function capNotice(): ?string
    {
        return $this->registrationRefusal(__('webhooks::self-service.list.cap_reached'));
    }

    /**
     * Page the list with a design-system control rather than Livewire's built-in one, whose markup
     * paints a raw color palette no design token reaches and whose landmark carries a hardcoded
     * English accessible name.
     *
     * This screen is rendered from WireKit components throughout, so in practice the chooser
     * answers with WireKit's own pager — {@see UiVariant::paginationView()}. It is still asked
     * rather than hardcoded: the answer stays right if this screen ever grows a neutral rendering,
     * and a host that forces `webhooks.ui.variant` to plain gets a pager instead of an unresolvable
     * view name. Only paginationView() is set here because this list builds a length-aware
     * paginator; paginationSimpleView() would answer a question nothing asks.
     */
    public function paginationView(): string
    {
        return UiVariant::paginationView();
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::self-service.placeholders.list');
    }

    public function render(): View
    {
        // Clamped, because a public Livewire property is writable from the browser and
        // Builder::limit() silently drops a non-positive value — a page size the reader
        // controls is one they can set to a value that pages nothing, and the component
        // would read every row it can see, in one request.
        // `latest()` alone is not a total order. created_at has second resolution, and a
        // paginated read is several queries: where two rows tie the database may order them
        // differently per query, so a reader sees one row twice and another never, with every
        // individual page correct. The primary key is unique and makes the order total.
        $endpoints = $this->scopedQuery()->latest()->orderByDesc('id')->paginate(max(1, min($this->perPage, 100)));

        return ViewFactory::make('webhooks::self-service.livewire.endpoint-list', [
            'endpoints' => $endpoints,
            'allowDelete' => $this->deletionAllowed(),
            // Resolved once for the page, not once per row: the answer is a property of the
            // route table, and asking it per row would only make a long list slower.
            'showTransformLink' => PortalRoutes::has('webhooks.self-service.transform'),
        ]);
    }
}
