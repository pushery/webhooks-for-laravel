<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The routes this package's own macro registered, and the config name each one carries.
 *
 * Two preflight checks ask the same question first — which of the host's routes are receiving
 * routes — and a second copy of that predicate is a second copy that drifts. It lives here once.
 *
 * Asked of the CONTROLLER rather than of the route name, because a host may rename the route and
 * the controller is what makes it a receiving route.
 *
 * @internal
 */
final class ReceivingRoutes
{
    /**
     * @return list<Route>
     */
    public static function all(Router $router): array
    {
        $routes = [];

        // `getRoutes()` is typed as the collection INTERFACE, which is not iterable — the
        // concrete RouteCollection is. Asking it for its array is one call and keeps the loop
        // honestly typed rather than asserting around the declaration.
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getActionName() === WebhookController::class) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * The config name the macro pinned onto a route, or null when there is none.
     *
     * The macro puts it in the route's ACTION rather than in its defaults, so nothing in the URI
     * can reach it. Null is not reachable through the macro and is read defensively anyway: a
     * host can build the route by hand against the same controller.
     */
    public static function configNameOf(Route $route): ?string
    {
        $name = $route->getAction('webhookConfigName');

        // The fallback on its own statement, not as a ternary arm: line coverage credits a
        // one-line ternary to every path through it, so a `null` that never runs would read as
        // covered for ever. The rule and the pcov measurement behind it are in
        // tests/Feature/ConstantFallbackVisibilityTest.php.
        if (! is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    /**
     * A printable identity for a route, for a message a host has to act on.
     */
    public static function label(Route $route): string
    {
        return sprintf(
            // The verbs, filtered rather than cast: `methods()` is documented as a list of
            // strings and the framework's own stub widens it, so a cast would be asserting
            // around a declaration instead of reading it.
            '%s %s',
            implode('|', array_filter($route->methods(), is_string(...))),
            $route->uri(),
        );
    }
}
