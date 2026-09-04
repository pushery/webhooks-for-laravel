<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Facades\Route;
use Pushery\Webhooks\Support\Http\UiScriptController;

/**
 * The package's own front-end asset: one small JavaScript file, served from the application's
 * own origin by a route this package registers.
 *
 * A file from 'self' rather than an inline script, and the difference is the whole point. The two
 * Alpine factories these screens mount used to be registered by an inline <script> inside each
 * view's @assets block, with an optional CSP nonce. On a host running a strict, nonce-less policy —
 * `script-src 'self'`, which an application is entitled to choose and which the package must not
 * ask it to loosen — the browser refuses to run it. Nothing throws, nothing reaches a server log,
 * and a CSP audit reads the expression as perfectly valid: the panel is simply dead. That was the
 * third distinct cause of the same dead surface, which is why the answer chosen here is the one
 * with no policy dependency left, instead of another correction inside the inline path.
 *
 * It is served rather than published. A publishable asset would work only for a host that remembers
 * to run `vendor:publish` — and to run it again after every upgrade. The failure of forgetting is
 * the same silent dead panel this replaces, so the file is served instead: it is always present,
 * always the version the installed package ships, and needs nothing from the host.
 *
 * No middleware, and that is deliberate. The response is a static file with no user data in it.
 * Putting it behind the host's auth stack would make it a session-bearing request per page load,
 * and behind the dashboard's gate it would 403 for exactly the readers who are allowed to see the
 * screen but arrive before their session is established.
 *
 * url() resolves through route() and is allowed to throw. A host upgrading with a stale route cache
 * has no such route, and route() then raises RouteNotFoundException — a 500 on the dashboard until
 * `route:cache` is rebuilt. That is the correct failure and it is not to be softened: the obvious
 * fix, falling back to url(self::URI), emits a path that the cached route table does not serve, so
 * the browser gets a 404 for the script and the panel is dead again without saying so. Silent death
 * is the exact defect this whole seam replaces, three times over. Every package that adds a route
 * carries the same contract, a deploy that skips its own cache rebuild is already broken, and the
 * exception names the route it could not find.
 *
 * @internal
 */
final class UiAssets
{
    /**
     * The route name the views resolve. Held as a constant because it is the one string the
     * registration and the URL builder have to agree on, and a typo in either is a
     * RouteNotFoundException on a screen rather than a failing test.
     */
    public const string ROUTE = 'webhooks.ui.script';

    /**
     * The URI it is served from. Deliberately under the package's own segment rather than a
     * host-configurable path: it carries no user data, collides with nothing a host is likely
     * to own, and one fewer configurable string is one fewer way for the script to 404.
     */
    public const string URI = '_webhooks/webhooks-ui.js';

    /**
     * The content hash, computed once per process.
     *
     * It is the cache-buster AND the ETag. Keyed to the file's CONTENT rather than to the
     * package version, so a host running a dev checkout picks up an edit, and a version bump
     * that does not touch the file does not force every browser to re-download it.
     */
    private static ?string $version = null;

    public static function file(): string
    {
        return dirname(__DIR__, 2).'/resources/js/webhooks-ui.js';
    }

    public static function version(): string
    {
        return self::$version ??= substr(hash_file('sha256', self::file()) ?: 'dev', 0, 12);
    }

    /**
     * Drop the memoized hash. For tests that rewrite the file; a running application never
     * needs it, because the file cannot change under it.
     */
    public static function forgetVersion(): void
    {
        self::$version = null;
    }

    public static function url(): string
    {
        return route(self::ROUTE, ['id' => self::version()]);
    }

    /**
     * Register the asset route, once.
     *
     * Called from every provider whose screens mount these components — the dashboard and the
     * self-service portal — because either may be enabled without the other, and a headless
     * or send-only host should carry no route it will never serve. The guard makes the second
     * call a no-op rather than a duplicate route, which would otherwise be resolved by
     * whichever definition Laravel saw last.
     */
    public static function registerRoute(): void
    {
        if (Route::has(self::ROUTE)) {
            return;
        }

        Route::get(self::URI, UiScriptController::class)->name(self::ROUTE);
    }
}
