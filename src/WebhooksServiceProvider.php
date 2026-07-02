<?php

declare(strict_types=1);

namespace Webhooks;

use GuzzleHttp\HandlerStack;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use Webhooks\Console\PartitionMaintenanceCommand;
use Webhooks\Contracts\EndpointGuard;
use Webhooks\Contracts\HostResolver;
use Webhooks\Jobs\GuardedWebhookCall;
use Webhooks\Listeners\WebhookServerEventSubscriber;
use Webhooks\Ssrf\DefaultEndpointGuard;
use Webhooks\Ssrf\SystemHostResolver;

final class WebhooksServiceProvider extends ServiceProvider
{
    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webhooks.php', 'webhooks');

        $this->app->singleton(HostResolver::class, SystemHostResolver::class);

        $this->app->singleton(EndpointGuard::class, function (Application $app): EndpointGuard {
            /** @var array{https_only?: bool, block_private_networks?: bool, allowed_hosts?: list<string>, blocked_hosts?: list<string>} $endpoints */
            $endpoints = config('webhooks.endpoints', []);

            return new DefaultEndpointGuard(
                $app->make(HostResolver::class),
                $endpoints['https_only'] ?? true,
                $endpoints['block_private_networks'] ?? true,
                $endpoints['allowed_hosts'] ?? [],
                $endpoints['blocked_hosts'] ?? [],
            );
        });

        // The Guzzle handler behind the delivery job. Bound so tests can swap in a
        // mock handler; production gets Guzzle's default (real) handler.
        $this->app->bind(HandlerStack::class, static fn (): HandlerStack => HandlerStack::create());

        $this->app->singleton(WebhookManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webhooks');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'webhooks');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        // Deliver through the SSRF-hardened job. It only hardens this package's own
        // deliveries (those carrying our meta), so the host app can still use spatie
        // directly; an app that sets its own webhook_job later overrides this.
        config()->set('webhook-server.webhook_job', GuardedWebhookCall::class);

        Event::subscribe(WebhookServerEventSubscriber::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('webhooks:partition-maintenance')->daily();
        });

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->commands([PartitionMaintenanceCommand::class]);
        }
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/webhooks.php' => config_path('webhooks.php'),
        ], 'webhooks-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'webhooks-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/webhooks'),
        ], 'webhooks-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/webhooks'),
        ], 'webhooks-lang');
    }
}
