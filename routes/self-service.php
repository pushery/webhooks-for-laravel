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
        //
        // Constrained because the segment reaches the query unfiltered: a key the column
        // cannot hold made Postgres refuse it, and the reader got a 500 that confirmed both
        // the route and the database behind it. Refusing the match instead means an unusable
        // segment is a 404 with nothing caught -- and catching the query exception would have
        // been the worse trade, since it swallows real database errors on the same route.
        //
        // Bounded LENGTH, not just whereNumber(): digits alone leave the hole open at the
        // other end. `9999999999999999999999999` is all digits, so whereNumber() passes it
        // through, and the column rejects it for RANGE instead of syntax -- the same 500,
        // reached just as easily. Eighteen digits is the widest run that always fits a signed
        // bigint (10^18 - 1 < 2^63 - 1), so this refuses exactly what the column cannot hold
        // and nothing a real key could ever be.
        Route::get('{subscription}/transform', PayloadTransformEditor::class)
            ->where('subscription', '[0-9]{1,18}')
            ->name('webhooks.self-service.transform');
    });
