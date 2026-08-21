<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Override;
use Pushery\Webhooks\Platform\Livewire\EndpointDeliveries;
use Pushery\Webhooks\Platform\Livewire\EndpointForm;
use Pushery\Webhooks\Platform\Livewire\EndpointHealthMatrix;
use Pushery\Webhooks\Platform\Livewire\EndpointList;
use Pushery\Webhooks\Platform\Livewire\EndpointSecretPanel;
use Pushery\Webhooks\Platform\Livewire\PayloadTransformEditor;
use Pushery\Webhooks\Platform\Livewire\SelfServicePortalPage;
use Pushery\Webhooks\Support\MergesPackageConfig;
use Pushery\Webhooks\Support\PlatformRequirement;

/**
 * Boots the self-service endpoint portal — the customer-facing Livewire/WireKit
 * surface where a tenant manages its OWN webhook endpoints — but ONLY when
 * webhooks.platform.self_service.enabled. This provider is NOT auto-registered
 * (absent from composer extra.laravel.providers): register it in a host app to expose
 * the portal, so a send-only or operator-only consumer never pays for it.
 *
 * The manage-webhook-endpoints gate and the row-level WebhookSubscriptionPolicy that
 * the portal authorizes against are registered by the main WebhooksServiceProvider
 * (also gated on the same switch); this provider adds only the presentation layer —
 * the Livewire components, the mount routes, and the publishable views — so the two
 * halves stay independently testable.
 *
 * The panels and the pages are separately switchable: with
 * webhooks.platform.self_service.register_routes false this provider registers the
 * components and mounts nothing, which is what a host embedding a panel in its own
 * guarded screen wants.
 */
final class SelfServicePortalServiceProvider extends ServiceProvider
{
    use MergesPackageConfig;

    #[Override]
    public function register(): void
    {
        $this->mergePackageConfig();
    }

    /**
     * The single switch the whole portal hangs off, exposed so the conditional itself
     * is directly testable.
     */
    public function shouldBoot(): bool
    {
        return Config::boolean('webhooks.platform.self_service.enabled', false);
    }

    public function boot(): void
    {
        if (! $this->shouldBoot()) {
            return;
        }

        // The portal manages Platform's endpoints, and the gate + policy it authorizes
        // against are registered by the Platform provider — both of which are gone with
        // Platform off. Refuse to boot rather than mount screens with no table behind
        // them and no ability defined to guard them.
        PlatformRequirement::ensure(
            'self-service portal',
            'webhooks.platform.self_service.enabled',
            'manages the endpoints (webhook_subscriptions)',
        );

        $this->registerPanels();

        if ($this->shouldRegisterRoutes()) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/self-service.php');
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }
    }

    /**
     * Whether the portal also mounts its OWN pages, or only registers the panels.
     *
     * These were one decision until a host tried to embed a panel in a screen it already
     * guards: registering the provider was all-or-nothing, and the all included a second
     * URL onto the same surface, carrying the portal's own middleware rather than the
     * host's. Declining the provider left the panels unregistered, which is why they were
     * not embeddable at all. Splitting the two makes "give me the components" expressible
     * without also saying "and publish them at a URL of your own".
     *
     * Defaults to true, so a host that never sets it keeps the whole portal.
     */
    public function shouldRegisterRoutes(): bool
    {
        return Config::boolean('webhooks.platform.self_service.register_routes', true);
    }

    /**
     * Register the class-based Livewire panels under a stable, dotted alias each, so a
     * host page can also embed a single panel by name.
     */
    private function registerPanels(): void
    {
        Livewire::component('webhooks.self-service.portal', SelfServicePortalPage::class);
        Livewire::component('webhooks.self-service.endpoint-list', EndpointList::class);
        Livewire::component('webhooks.self-service.endpoint-form', EndpointForm::class);
        Livewire::component('webhooks.self-service.secret-panel', EndpointSecretPanel::class);
        Livewire::component('webhooks.self-service.endpoint-deliveries', EndpointDeliveries::class);
        Livewire::component('webhooks.self-service.health-matrix', EndpointHealthMatrix::class);
        Livewire::component('webhooks.self-service.transform-editor', PayloadTransformEditor::class);
    }

    /**
     * The portal Blade views are publishable on their own tag, so a host on a different
     * UI kit can hand-edit them while keeping the rest of the package.
     */
    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../../resources/views/self-service' => resource_path('views/vendor/webhooks/self-service'),
        ], 'webhooks-self-service-views');
    }
}
