<?php

declare(strict_types=1);

namespace Webhooks\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Webhooks\Core\Http\Exceptions\BlockedDestination;
use Webhooks\Facades\Webhooks;
use Webhooks\Livewire\Concerns\AuthorizesOperatorActions;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\Settings;

/**
 * The OPERATOR console for webhook endpoints: register one, switch it on or off, delete
 * it. A published stub — restyle it and make it yours.
 *
 * It is deliberately UNSCOPED, and unauthorized by default: it lists and mutates EVERY
 * endpoint in the installation regardless of owner, and the endpoints it registers are
 * global (owner-less), so every tenant's events reach them. That is what an operator screen
 * is for — and it means the component MUST be embedded behind an operator-only gate of your
 * own. It is not a tenant-facing surface, and putting it on one leaks endpoints across
 * tenants.
 *
 * That gate stays yours and stays required. What create(), toggle() and delete() add is a
 * check at the moment the action runs, which your page gate cannot give: set
 * webhooks.admin.ability, or override authorizeAction() in a subclass. Left unset it does
 * nothing, so this component behaves exactly as it always has. See
 * {@see AuthorizesOperatorActions} for why the two differ.
 *
 * The tenant-facing surface is the self-service portal
 * (`Webhooks\Platform\Livewire\EndpointList`), which is owner-scoped and
 * policy-guarded on every action.
 */
final class SubscriptionManager extends Component
{
    use AuthorizesOperatorActions;

    public string $name = '';

    public string $url = '';

    /** @var array<int, string> */
    public array $eventTypes = [];

    /** The plaintext signing secret is shown once, right after creation. */
    public ?string $newSecret = null;

    public function create(): void
    {
        $this->authorizeAction('create');

        $accepted = new Settings()->acceptedEventTypes();

        $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            // Cap the URL at the MySQL column width so it stores the same on every
            // supported engine (varchar(2048) there, unbounded text on Postgres).
            'url' => ['required', 'url', 'max:2048'],
            'eventTypes' => ['required', 'array', 'min:1'],
            // Constrained to the catalog when the host keeps one, and unconstrained when it
            // does not — the catalog ships empty. An operator registers a GLOBAL endpoint
            // here, so a typo costs every tenant's events for that type, not one tenant's.
            'eventTypes.*' => $accepted === null ? ['string'] : ['string', Rule::in($accepted)],
        ], [
            'eventTypes.*.in' => __('webhooks::management.validation.event_types.in'),
        ]);

        try {
            $subscription = Webhooks::subscribe(null, $this->url, array_values($this->eventTypes), $this->name ?: null);
        } catch (BlockedDestination) {
            // The guard's own message stays out of the form: it is an operator
            // diagnostic for the log, and it would tell a stranger which hosts resolve
            // where. The reader gets a translated sentence they can act on.
            $this->addError('url', __('webhooks::management.validation.url.blocked'));

            return;
        }

        $this->newSecret = $subscription->secret;
        $this->reset(['name', 'url', 'eventTypes']);
    }

    /**
     * Switch an endpoint on or off through the manager, so this console cannot drift from
     * what activation means anywhere else — most importantly, re-enabling clears the
     * circuit-breaker streak, without which the endpoint would disable itself again on
     * its next final failure.
     */
    public function toggle(int $id): void
    {
        $this->authorizeAction('toggle');

        $subscription = WebhookSubscription::query()->findOrFail($id);

        $subscription->is_active
            ? Webhooks::disable($subscription)
            : Webhooks::enable($subscription);
    }

    /**
     * Permanently remove an endpoint (and, by FK cascade, its delivery log). To stop
     * delivering while keeping the history, toggle it off instead.
     */
    public function delete(int $id): void
    {
        $this->authorizeAction('delete');

        Webhooks::unsubscribe(WebhookSubscription::query()->findOrFail($id));
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::livewire.subscription-manager', [
            'subscriptions' => WebhookSubscription::query()->latest()->get(),
            'availableEventTypes' => new Settings()->eventTypes(),
        ]);
    }
}
