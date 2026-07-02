<?php

declare(strict_types=1);

namespace Webhooks\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Component;
use Webhooks\Exceptions\BlockedEndpointException;
use Webhooks\Facades\Webhooks;
use Webhooks\Models\WebhookSubscription;

/**
 * Register and manage webhook endpoints. A published stub — restyle it and place
 * it behind your own authorization.
 */
final class SubscriptionManager extends Component
{
    public string $name = '';

    public string $url = '';

    /** @var array<int, string> */
    public array $eventTypes = [];

    /** The plaintext signing secret is shown once, right after creation. */
    public ?string $newSecret = null;

    public function create(): void
    {
        $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'url'],
            'eventTypes' => ['required', 'array', 'min:1'],
            'eventTypes.*' => ['string'],
        ]);

        try {
            $subscription = Webhooks::subscribe(null, $this->url, array_values($this->eventTypes), $this->name ?: null);
        } catch (BlockedEndpointException $exception) {
            $this->addError('url', $exception->getMessage());

            return;
        }

        $this->newSecret = $subscription->secret;
        $this->reset(['name', 'url', 'eventTypes']);
    }

    public function toggle(int $id): void
    {
        $subscription = WebhookSubscription::query()->findOrFail($id);

        $subscription->is_active = ! $subscription->is_active;
        $subscription->disabled_at = $subscription->is_active ? null : now();
        $subscription->consecutive_failures = 0;
        $subscription->save();
    }

    public function delete(int $id): void
    {
        WebhookSubscription::query()->whereKey($id)->delete();
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::livewire.subscription-manager', [
            'subscriptions' => WebhookSubscription::query()->latest()->get(),
            'availableEventTypes' => array_keys(Config::array('webhooks.catalog', [])),
        ]);
    }
}
