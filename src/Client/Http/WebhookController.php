<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Pushery\Webhooks\Client\Exceptions\WebhookConfigCannotVerify;
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

        try {
            $config = WebhookConfig::forName($name);
        } catch (WebhookConfigCannotVerify $cannotVerify) {
            // The processor's promise — a request that can never be made valid is refused,
            // never answered 5xx — has to hold for the one refusal the processor cannot
            // reach. There is nothing to check the signature against, so there is nothing
            // a retry could fix, and abort() is deliberately the same call the rejected
            // signature makes: a producer must not be able to tell the two apart, and a
            // host reading its own logs must not find two shapes for one event.
            //
            // Reported first, and only here: an uncaught one is reported by the framework
            // through the same report() on the exception, so neither path logs twice.
            report($cannotVerify);

            abort($cannotVerify->status);
        }

        return new WebhookProcessor($request, $config)->process();
    }
}
