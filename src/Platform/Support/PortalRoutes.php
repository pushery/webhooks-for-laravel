<?php

declare(strict_types=1);

namespace Webhooks\Platform\Support;

use Illuminate\Support\Facades\Route;

/**
 * Resolves a self-service portal route only if it is actually registered.
 *
 * The panels are meant to be embedded — a host can drop
 * <livewire:webhooks.self-service.endpoint-list /> into a screen it already guards and skip
 * the portal's own pages entirely. A panel that names a route unconditionally cannot survive
 * that: route() throws, and it throws from inside a view, so the whole screen 500s.
 *
 * What makes this worth a named seam rather than a bare Route::has() at each site is WHEN it
 * goes wrong. The links that need a route are drawn per row, so an installation with no
 * endpoints yet renders perfectly and the failure waits for the first real one. An adoption
 * checked against a fresh account looks complete right up to the moment a customer has data.
 *
 * The condition is route registration and nothing else. In particular the transform editor's
 * link is NOT also gated on payload_versioning: that editor deliberately works while
 * versioning is off — it stores rules that take effect once the feature is switched on, and
 * says so in a callout — so hiding the link there would remove working functionality rather
 * than protect anyone.
 *
 * @internal
 */
final class PortalRoutes
{
    /**
     * Whether one portal route is registered, so a link to it can be drawn at all.
     */
    public static function has(string $name): bool
    {
        return Route::has($name);
    }

    /**
     * The URL of one portal route, or null when that route is not registered.
     *
     * Null rather than an empty string: a view has to be able to tell "no link" from "a link
     * to nowhere", and an empty href is a link to the current page.
     */
    public static function url(string $name, mixed ...$parameters): ?string
    {
        return self::has($name) ? route($name, $parameters) : null;
    }
}
