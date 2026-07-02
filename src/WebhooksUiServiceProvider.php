<?php

declare(strict_types=1);

namespace Webhooks;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Webhooks\Livewire\DeliveryLog;
use Webhooks\Livewire\SubscriptionManager;

/**
 * Optional management UI. This provider is NOT auto-registered — register it in a
 * host application (e.g. Larion) to expose the Livewire management components, so
 * the core package stays headless. It requires livewire/livewire.
 *
 * Embed the components in your own (branded, authorised) pages:
 *
 *     <livewire:webhooks-subscriptions />
 *     <livewire:webhooks-deliveries />
 *
 * Two publishable stub variants render the same components; publish exactly one:
 *
 *     php artisan vendor:publish --tag=webhooks-ui           # neutral Tailwind stubs
 *     php artisan vendor:publish --tag=webhooks-ui-wirekit   # pushery/wirekit-styled stubs
 *
 * Both land at resources/views/vendor/webhooks/livewire, which overrides the
 * package defaults — restyle from there to match your app.
 */
final class WebhooksUiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('webhooks-subscriptions', SubscriptionManager::class);
        Livewire::component('webhooks-deliveries', DeliveryLog::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views/livewire' => resource_path('views/vendor/webhooks/livewire'),
            ], 'webhooks-ui');

            // The WireKit-styled variant of the same components; overrides the same
            // published paths, so a host publishes exactly one of the two tags.
            $this->publishes([
                __DIR__.'/../resources/views/wirekit' => resource_path('views/vendor/webhooks/livewire'),
            ], 'webhooks-ui-wirekit');
        }
    }
}
