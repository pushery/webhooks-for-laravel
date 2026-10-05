<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Pushery\Webhooks\Dashboard\Http\WebhookMetricsController;
use Pushery\Webhooks\Dashboard\Livewire\WebhooksDashboardPage;

// The customer-facing dashboard route. Loaded by the dashboard service provider
// only when the layer is enabled. Both the middleware stack (which carries the
// view-webhook-dashboard gate) and the URL prefix are configurable, so a host
// mounts the page wherever its own app chrome expects it.
Route::middleware(Config::array('webhooks.dashboard.middleware', ['web', 'auth', 'can:view-webhook-dashboard']))
    ->prefix(Config::string('webhooks.dashboard.prefix', 'webhooks'))
    ->group(function (): void {
        Route::get('/', WebhooksDashboardPage::class)->name('webhooks.dashboard');

        // The read-only JSON metrics endpoint, off unless the host opts in: with
        // expose_json_api false the route is never registered at all, so the flag is a
        // real gate rather than a runtime refusal. It shares this group's middleware
        // (and therefore the view-webhook-dashboard gate) with the page it mirrors.
        if (Config::boolean('webhooks.dashboard.expose_json_api', false)) {
            $metrics = Route::get(
                Config::string('webhooks.dashboard.api_path', 'api/metrics'),
                WebhookMetricsController::class,
            )->name('webhooks.dashboard.metrics');

            // A brake of its own, on this route alone: every request computes live percentiles
            // over the window it asks for, and a throttle in the group's stack would brake the
            // page's own requests as well. 0 switches it off.
            $perMinute = Config::integer('webhooks.dashboard.api_max_per_minute', 60);

            if ($perMinute > 0) {
                $metrics->middleware('throttle:'.$perMinute.',1');
            }
        }
    });
