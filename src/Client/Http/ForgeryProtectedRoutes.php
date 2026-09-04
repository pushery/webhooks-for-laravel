<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The receiving routes a host has put behind CSRF protection, where no delivery can ever arrive.
 *
 * This is not a style preference, it is a route that cannot work. Laravel's `web` group carries
 * `PreventRequestForgery`, which throws `TokenMismatchException`, which the exception handler
 * renders as **419**. A producer POSTing a webhook has no session and no token, so every delivery
 * to such a route is rejected before the controller runs — before the signature is verified, before
 * anything is logged, before any event fires. Nothing in this package sees it.
 *
 * And 419 is the worst status it could be. Most producers treat 4xx as permanent and stop
 * retrying, so the deliveries are not delayed, they are gone — which is the same reasoning the
 * pipeline uses to answer an unverifiable signature 401 rather than 500: a status decides whether
 * the sender ever comes back.
 *
 * The failure is easy to walk into, because nothing about it is visible from inside the
 * application. `Route::webhooks()` is shown in the quickstart and on the receiving page without
 * naming a route FILE, and `routes/web.php` is where a Laravel developer puts a route by habit.
 * The host sees a route that exists, a config entry that is correct, and a producer dashboard
 * full of failures.
 *
 * Why this reports rather than repairs. The macro could strip the middleware itself, and that was
 * the first shape considered — a webhook receiver has no use for CSRF, so removing it can only ever
 * remove rejections. It is not done, because a package that silently deletes a middleware the host
 * asked for is a worse habit than the bug it fixes: the next reader cannot tell which of their
 * middleware still applies. Preflight is where this package already names a configuration that will
 * not carry traffic, so it says it there, once, in the words that let a host fix it in one move.
 *
 * @internal
 */
final class ForgeryProtectedRoutes
{
    /**
     * The forgery middleware.
     *
     * `gatherMiddleware()` returns whatever the host wrote — a class name, an alias, or a
     * subclass they swapped in — so the comparison below is against a string either way. Written
     * as `::class` rather than as a literal so a framework rename is a compile error here instead
     * of a check that quietly stops matching.
     */
    private const string FORGERY = PreventRequestForgery::class;

    /**
     * @return list<string> one sentence per affected route, empty when there is nothing to say
     */
    public static function faults(Router $router): array
    {
        $faults = [];

        // `getRoutes()` is typed as the collection INTERFACE, which is not iterable — the
        // concrete RouteCollection is. Asking it for its array is one call and keeps the loop
        // honestly typed rather than asserting around the declaration.
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! self::isReceivingRoute($route) || ! self::isForgeryProtected($route)) {
                continue;
            }

            $faults[] = sprintf(
                'The receiving route [%s %s] runs behind CSRF protection, so every delivery to it '
                .'is answered 419 before the signature is ever verified. A producer has no session '
                .'and no token, and most treat 4xx as permanent — the deliveries are not delayed, '
                .'they are lost. Move the Route::webhooks() call out of routes/web.php (routes/api.php '
                .'is the usual home), or exclude the forgery middleware for this route.',
                // The verbs, filtered rather than cast: `methods()` is documented as a list of
                // strings and the framework's own stub widens it, so a cast would be asserting
                // around a declaration instead of reading it.
                implode('|', array_filter($route->methods(), is_string(...))),
                $route->uri(),
            );
        }

        return $faults;
    }

    /**
     * Whether this route is one the package's own macro registered.
     *
     * Asked of the CONTROLLER rather than of the route name, because a host may rename the route
     * and the controller is what makes it a receiving route.
     */
    private static function isReceivingRoute(Route $route): bool
    {
        return $route->getActionName() === WebhookController::class;
    }

    private static function isForgeryProtected(Route $route): bool
    {
        // No `is_string()` skip here, and that is measured rather than assumed. The obvious
        // defensive guard was written first and the coverage floor refused it: nothing can put a
        // non-string in this list for one of our routes. `Route::middleware()` casts every entry it
        // is handed (Route.php:1090), `RouteRegistrar` does the same for a group — a closure there
        // throws at registration — and the only other source, controller middleware, needs a
        // controller implementing HasMiddleware. This route's action is pinned to WebhookController
        // by the macro, and `isReceivingRoute()` above is what selects on it.
        //
        // A branch no run can enter is not caution, it is a line that reads as tested for ever.
        foreach ($route->gatherMiddleware() as $middleware) {
            // Three spellings reach the same middleware and a check on one of them is a check
            // that mostly works: the class itself, a host's subclass of it (the documented way
            // to add an exception URI), and the `web` group when the router has not expanded it
            // yet. The group is included because a route registered inside `Route::middleware('web')`
            // reports the GROUP here, not its members — measured, and it is the ordinary case.
            //
            // The `is_string()` sits INSIDE the condition rather than above it as a skip: the
            // declared type of this list is `mixed[]`, so `is_subclass_of()` needs the narrowing —
            // but as its own branch it would be a line no run can enter, which is the shape the
            // comment above rejects. On one condition it is a short-circuit within a line that
            // already runs.
            if ($middleware === self::FORGERY
                || $middleware === 'web'
                || (is_string($middleware) && is_subclass_of($middleware, self::FORGERY))) {
                return true;
            }
        }

        return false;
    }
}
