<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\Webhooks\Client\Console\ImportSpatieCallsCommand;
use Pushery\Webhooks\Client\Http\CaptureRawBody;
use Pushery\Webhooks\Client\Http\WebhookController;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Support\MergesPackageConfig;

/**
 * Boots the inbound Client layer, but only when webhooks.client.enabled — an app
 * that just sends never pays for a receiver. When enabled it registers the
 * Route::webhooks() macro, prepends the raw-body-capture middleware, loads the
 * webhook_calls migration and schedules pruning. When disabled it does nothing.
 */
final class WebhookClientServiceProvider extends ServiceProvider
{
    use MergesPackageConfig;

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
        $this->mergePackageConfig();
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
            if (! Config::boolean('webhooks.schedule.enabled', true)) {
                return;
            }

            // Both guards, for the reasons given at the server-side prune: the scheduler fires
            // on every application server, so onOneServer() is what keeps one deleter instead of
            // N, and the bounded overlap guard keeps a long first run over a large call log from
            // being joined by the next day's.
            $schedule->command('model:prune', ['--model' => [WebhookCall::class]])
                ->daily()
                ->withoutOverlapping(360)
                ->onOneServer();
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

            // The name goes in the ACTION, not in defaults(), and the difference is whether
            // the binding is a promise or a suggestion. A defaults() value is only what the
            // parameter falls back to -- so a URI written with a literal {webhookConfigName}
            // segment let the CALLER pick the config, and a route meant to be pinned to one
            // source processed a delivery under another. The signature still had to match the
            // chosen config, so nothing was forged; what was lost is the binding itself, and the
            // stored row then disagreed with the route about where the delivery came from.
            //
            // Not far-fetched: the parameter name is readable in any route listing, and a URI
            // with placeholders is the obvious shape for a multi-tenant mount.
            //
            // An action value has no such fallback semantics -- nothing in the URI can reach it.
            return RouteFacade::match([strtoupper($verb)], $url, [
                'uses' => WebhookController::class,
                'webhookConfigName' => $name,
            ])->name("webhooks.{$name}");
        });
    }
}
