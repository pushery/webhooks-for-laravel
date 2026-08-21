<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Pushery\Webhooks\Client\WebhookConfig;
use Pushery\Webhooks\Client\WebhookProcessor;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single invokable endpoint every Route::webhooks() route points at. It reads
 * the config name the macro pinned onto the route, resolves the matching config and
 * runs the whole receiving pipeline through the {@see WebhookProcessor}. The
 * pipeline can also be driven directly (controller-less) for full parity.
 *
 * @internal
 */
final class WebhookController
{
    public function __invoke(Request $request): Response
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->parameter('webhookConfigName') : null;

        if (! is_string($name)) {
            throw new RuntimeException('The webhooks route is missing its config name; register it with the Route::webhooks() macro.');
        }

        return new WebhookProcessor($request, WebhookConfig::forName($name))->process();
    }
}
