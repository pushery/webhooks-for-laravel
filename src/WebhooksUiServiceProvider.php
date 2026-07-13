<?php

declare(strict_types=1);

namespace Webhooks;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Webhooks\Livewire\DeliveryLog;
use Webhooks\Livewire\SubscriptionManager;

/**
 * The optional OPERATOR console. This provider is NOT auto-registered — register it in a
 * host application to expose the two Livewire components, so the core package stays
 * headless. It requires livewire/livewire.
 *
 * Embed them in your own branded pages, BEHIND AN OPERATOR-ONLY GATE:
 *
 *     <livewire:webhooks.admin.subscriptions />
 *     <livewire:webhooks.admin.deliveries />
 *
 * Both components are deliberately unscoped and carry no authorization of their own:
 * they show and mutate EVERY tenant's endpoints and deliveries, which is what an
 * operator screen is for and exactly what a tenant may never see. The customer-facing
 * equivalents are the self-service portal
 * (`Webhooks\Platform\SelfServicePortalServiceProvider`) and the observability
 * dashboard (`Webhooks\Dashboard\WebhooksDashboardServiceProvider`), both of which
 * are owner-scoped and policy-guarded — use those for anything a customer touches.
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
        Livewire::component('webhooks.admin.subscriptions', SubscriptionManager::class);
        Livewire::component('webhooks.admin.deliveries', DeliveryLog::class);

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
