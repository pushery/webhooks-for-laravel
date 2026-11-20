<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use WeakMap;

/**
 * Whether a route of the application is one of this package's receiving routes, and the config name it receives for.
 *
 * An application asks it in a middleware of its own that sets a limit or a connection per receiving route:
 * {@see self::isReceiving()} and {@see self::configNameOf()} are public API. The other methods serve the package's own
 * checks, which ask the same question, and are internal.
 *
 * Asked of the controller rather than of the route name, because an application may rename the route and the
 * controller is what makes it a receiving route.
 */
final class ReceivingRoutes
{
    /**
     * The addresses of the receiving routes of each cached route collection, kept for as long as the collection lives.
     *
     * @var WeakMap<CompiledRouteCollection, list<array{methods: list<string>, path: string}>>|null
     */
    private static ?WeakMap $cached = null;

    /**
     * The receiving routes among every route the router holds.
     *
     * @internal
     *
     * @return list<Route>
     */
    public static function all(Router $router): array
    {
        $routes = [];

        // `getRoutes()` is typed as the collection interface, which is not iterable — the
        // concrete RouteCollection is. Asking it for its array is one call and keeps the loop
        // honestly typed rather than asserting around the declaration.
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (self::isReceiving($route)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * The method and the path pattern of each receiving route, for a request the router has not matched yet.
     *
     * A cached collection builds every route when it is asked for them, so its answer is kept for as long as the
     * collection lives, which under Octane is every request the worker serves. A collection that is not cached is
     * read each time, so that a route added to it later counts.
     *
     * @internal
     *
     * @return list<array{methods: list<string>, path: string}>
     */
    public static function addresses(Router $router): array
    {
        $collection = $router->getRoutes();

        if ($collection instanceof CompiledRouteCollection && isset(self::$cached[$collection])) {
            return self::$cached[$collection];
        }

        $addresses = [];

        foreach (self::all($router) as $route) {
            $addresses[] = [
                'methods' => array_values(array_filter($route->methods(), is_string(...))),
                'path' => $route->toSymfonyRoute()->compile()->getRegex(),
            ];
        }

        if ($collection instanceof CompiledRouteCollection) {
            self::$cached ??= new WeakMap;
            self::$cached[$collection] = $addresses;
        }

        return $addresses;
    }

    /**
     * Whether the route is a receiving route, by its controller.
     */
    public static function isReceiving(Route $route): bool
    {
        return $route->getActionName() === WebhookController::class;
    }

    /**
     * The config name the macro pinned onto a route, or null when there is none.
     *
     * The macro puts it in the route's action rather than in its defaults, so nothing in the URI
     * can reach it. Null is not reachable through the macro and is read defensively anyway: a
     * host can build the route by hand against the same controller.
     */
    public static function configNameOf(Route $route): ?string
    {
        $name = $route->getAction('webhookConfigName');

        if (! is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    /**
     * A printable identity for a route, for a message a host has to act on.
     *
     * @internal
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
