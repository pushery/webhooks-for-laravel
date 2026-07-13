<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Webhooks\Dashboard\Http\WebhookMetricsController;
use Webhooks\Dashboard\Livewire\WebhooksDashboardPage;

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
            Route::get(
                Config::string('webhooks.dashboard.api_path', 'api/metrics'),
                WebhookMetricsController::class,
            )->name('webhooks.dashboard.metrics');
        }
    });
