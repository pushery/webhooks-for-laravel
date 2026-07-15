<?php

declare(strict_types=1);

namespace Webhooks\Client;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\ServiceProvider;
use Override;
use Webhooks\Client\Console\ImportSpatieCallsCommand;
use Webhooks\Client\Http\CaptureRawBody;
use Webhooks\Client\Http\WebhookController;
use Webhooks\Client\Models\WebhookCall;

/**
 * Boots the inbound Client layer, but only when webhooks.client.enabled — an app
 * that just sends never pays for a receiver. When enabled it registers the
 * Route::webhooks() macro, prepends the raw-body-capture middleware, loads the
 * webhook_calls migration and schedules pruning. When disabled it does nothing.
 */
final class WebhookClientServiceProvider extends ServiceProvider
{
    /**
     * Whether the bundled client migration is registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage it in the host app instead.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/webhooks.php', 'webhooks');
    }

    /**
     * The single gate the whole layer hangs off: nothing below register() runs
     * unless the receiver is switched on. Exposed so the conditional itself is
     * directly testable.
     */
    public function shouldBoot(): bool
    {
        return Config::boolean('webhooks.client.enabled', false);
    }

    public function boot(): void
    {
        if (! $this->shouldBoot()) {
            return;
        }

        $this->registerRouteMacro();

        if (Config::boolean('webhooks.client.raw_body_capture', true) && $this->app->bound(Kernel::class)) {
            $this->app->make(Kernel::class)->prependMiddleware(CaptureRawBody::class);
        }

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/client');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ImportSpatieCallsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [WebhookCall::class]])->daily();
        });
    }

    /**
     * Register Route::webhooks($url, $name, $verb) — a named route for one config
     * entry, pinning the config name onto the route for the controller to read.
     */
    private function registerRouteMacro(): void
    {
        if (Router::hasMacro('webhooks')) {
            return;
        }

        Router::macro('webhooks', function (string $url, ?string $name = null, string $verb = 'post'): Route {
            $name ??= $url;

            return RouteFacade::match([strtoupper($verb)], $url, WebhookController::class)
                ->name("webhooks.{$name}")
                ->defaults('webhookConfigName', $name);
        });
    }
}
