<?php

declare(strict_types=1);

namespace Pushery\Webhooks;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Pushery\Webhooks\Livewire\DeliveryLog;
use Pushery\Webhooks\Livewire\SubscriptionManager;
use Pushery\Webhooks\Support\UiVariant;

/**
 * The optional operator console. This provider is not auto-registered — register it in a host
 * application to expose the two Livewire components, so the core package stays headless. It
 * requires livewire/livewire.
 *
 * Embed them in your own branded pages, behind an operator-only gate:
 *
 *     <livewire:webhooks.admin.subscriptions />
 *     <livewire:webhooks.admin.deliveries />
 *
 * Both components are deliberately unscoped and, by default, carry no authorization of
 * their own: they show and mutate EVERY tenant's endpoints and deliveries, which is what an
 * operator screen is for and exactly what a tenant may never see. Their mutating actions
 * DO honor `webhooks.admin.ability` once you set one — a check per action, which the page
 * gate above cannot give — but the page gate is what keeps the screen off a customer's
 * browser, and no config setting replaces it. The customer-facing
 * equivalents are the self-service portal
 * (`Pushery\Webhooks\Platform\SelfServicePortalServiceProvider`) and the observability
 * dashboard (`Pushery\Webhooks\Dashboard\WebhooksDashboardServiceProvider`), both of which
 * are owner-scoped and policy-guarded — use those for anything a customer touches.
 *
 * The same two components ship in two renderings — neutral Tailwind markup and
 * pushery/wirekit markup — and `webhooks.ui.variant` picks between them at render time.
 * `auto`, the default, takes the WireKit one when WireKit is registered in the host.
 *
 * Publishing is no longer how you choose a style, and that matters because a publish takes the
 * views out of the update path: the copy has to be kept byte-identical to the package or a fix
 * lands in one and not the other, and the way that goes wrong is an unstyled screen rather than an
 * error. {@see UiVariant} has the recorded case.
 *
 * The tags remain for what they are actually for — real changes to the markup:
 *
 *     php artisan vendor:publish --tag=webhooks-ui           # neutral Tailwind stubs
 *     php artisan vendor:publish --tag=webhooks-ui-wirekit   # pushery/wirekit-styled stubs
 *
 * Both land at resources/views/vendor/webhooks/livewire, and a view published there wins over
 * the configured variant — including one published from the WireKit tag before the setting
 * existed, which is why the resolution checks for an override first.
 *
 * It asks whether what resolves is NOT the package's own view rather than whether it sits under
 * the publish path, so a host that overrides these views from their own service provider is
 * respected the same way. Publishing is the common route, not the only one.
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
