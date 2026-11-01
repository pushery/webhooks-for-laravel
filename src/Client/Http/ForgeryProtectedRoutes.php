<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Container\Container;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

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
 * naming a route file, and `routes/web.php` is where a Laravel developer puts a route by habit.
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

        foreach (ReceivingRoutes::all($router) as $route) {
            $middleware = self::forgeryMiddleware($route);

            if ($middleware === null || self::excepts($middleware, $route)) {
                continue;
            }

            $faults[] = sprintf(
                'The receiving route [%s] runs behind CSRF protection, so every delivery to it '
                .'is answered 419 before the signature is ever verified. A producer has no session '
                .'and no token, and most treat 4xx as permanent — the deliveries are not delayed, '
                .'they are lost. Move the Route::webhooks() call out of routes/web.php (routes/api.php '
                .'is the usual home), or exclude the forgery middleware for this route.',
                ReceivingRoutes::label($route),
            );
        }

        return $faults;
    }

    /**
     * The forgery middleware the route runs behind, or null where it runs behind none.
     *
     * @return class-string<PreventRequestForgery>|null
     */
    private static function forgeryMiddleware(Route $route): ?string
    {
        // No `is_string()` skip here: nothing can put a non-string in this list for one of our
        // routes. `Route::middleware()` casts every entry it
        // is handed (Route.php:1090), `RouteRegistrar` does the same for a group — a closure there
        // throws at registration — and the only other source, controller middleware, needs a
        // controller implementing HasMiddleware. This route's action is pinned to WebhookController
        // by the macro, and `isReceivingRoute()` above is what selects on it.
        //
        // So every entry here is a string, and there is no branch for anything else.
        foreach ($route->gatherMiddleware() as $middleware) {
            // Three spellings reach the same middleware and a check on one of them is a check
            // that mostly works: the class itself, a host's subclass of it (the documented way
            // to add an exception URI), and the `web` group when the router has not expanded it
            // yet. The group is included because a route registered inside `Route::middleware('web')`
            // reports the GROUP here, not its members, and that is the ordinary case.
            //
            // The `is_string()` sits inside the condition rather than above it as a skip: the
            // declared type of this list is `mixed[]`, so `is_subclass_of()` needs the narrowing —
            // but as its own branch it would be a line no run can enter, which is the shape the
            // comment above rejects. On one condition it is a short-circuit within a line that
            // already runs.
            if ($middleware === self::FORGERY || $middleware === 'web') {
                return self::FORGERY;
            }

            if (is_string($middleware) && is_subclass_of($middleware, self::FORGERY)) {
                return $middleware;
            }
        }

        return null;
    }

    /**
     * Whether the middleware lets the route's path through without a token anyway.
     *
     * The rule is the one its own `handle()` applies: every path in `getExcludedPaths()`, which holds
     * what `preventRequestForgery(except: [...])` in `bootstrap/app.php` added and what a host subclass
     * lists in `$except`, trimmed of its slashes and matched with `Str::is()` against the path, as
     * `Request::is()` matches it. A route has no request yet, so it is matched against the route's
     * own URI. A full URL in the list is not read here: there is no host to compare it with, and the
     * route is reported rather than passed on a guess.
     *
     * @param  class-string<PreventRequestForgery>  $middleware
     */
    private static function excepts(string $middleware, Route $route): bool
    {
        $path = trim($route->uri(), '/');
        $path = $path === '' ? '/' : $path;

        // Static analysis knows what `make()` returns only for a class name written as a constant.
        // This one is read from the route and names the middleware or a subclass of it, and that is
        // what the container builds.
        /** @var PreventRequestForgery $forgery */
        $forgery = Container::getInstance()->make($middleware);

        foreach ($forgery->getExcludedPaths() as $except) {
            if (is_string($except) && Str::is($except === '/' ? $except : trim($except, '/'), $path)) {
                return true;
            }
        }

        return false;
    }
}
