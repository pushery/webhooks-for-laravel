<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Client\Http\ReceivingRoutes as Routes;

/**
 * The receiving routes that name a client config which is not configured.
 *
 * A typo in the second argument of `Route::webhooks()` produces a route that exists, resolves and
 * answers every anonymous delivery with a refusal, for ever. The deployment is green, the config
 * is valid — the entry the route names simply is not in it — and the first thing that reports the
 * mistake is the producer's own dashboard.
 *
 * This is the mirror of `webhooks.client.expected`, and the pair covers the two directions the
 * binding can be broken in. That setting is a host saying "a client called this must exist"; this
 * check needs no setting at all, because a registered route already IS that statement: nobody
 * mounts an endpoint for a source they do not expect to hear from.
 *
 * A failure rather than an advisory, for the same reason the CSRF check beside it is one: the
 * route cannot carry traffic. It is not a legal configuration that happens to be unusual.
 *
 * @internal
 */
final class UnboundReceivingRoutes
{
    /**
     * @return list<string> one sentence per affected route, empty when there is nothing to say
     */
    public static function faults(Router $router): array
    {
        $configured = [];

        foreach (Config::array('webhooks.client.configs', []) as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $configured[$entry['name']] = true;
            }
        }

        $faults = [];

        foreach (Routes::all($router) as $route) {
            $name = Routes::configNameOf($route);

            if ($name === null || isset($configured[$name])) {
                continue;
            }

            $faults[] = sprintf(
                'The receiving route [%s] is bound to the client config [%s], and no entry of that '
                .'name is defined in webhooks.client.configs. Every delivery to it is refused, '
                .'because there is nothing to verify a signature against. Fix the name in the '
                .'Route::webhooks() call, or add the entry.',
                Routes::label($route),
                $name,
            );
        }

        return $faults;
    }
}
