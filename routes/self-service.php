<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Webhooks\Platform\Livewire\EndpointHealthMatrix;
use Webhooks\Platform\Livewire\PayloadTransformEditor;
use Webhooks\Platform\Livewire\SelfServicePortalPage;

// The self-service endpoint portal routes. Loaded by the portal service provider only
// when the layer is enabled. Both the middleware stack (which should carry the auth +
// manage-webhook-endpoints gate) and the URL prefix are configurable, so a host mounts
// the pages wherever its own app chrome expects them.
Route::middleware(Config::array('webhooks.platform.self_service.middleware', ['web', 'auth']))
    ->prefix(Config::string('webhooks.platform.self_service.route_prefix', 'webhooks/endpoints'))
    ->group(function (): void {
        Route::get('/', SelfServicePortalPage::class)->name('webhooks.self-service');

        // The endpoint health status board — a sibling full-page screen of the portal.
        Route::get('health', EndpointHealthMatrix::class)->name('webhooks.self-service.health');

        // The per-endpoint payload transform editor. The {subscription} segment is
        // resolved by route-model binding; the editor re-authorizes ownership on mount.
        Route::get('{subscription}/transform', PayloadTransformEditor::class)
            ->name('webhooks.self-service.transform');
    });
