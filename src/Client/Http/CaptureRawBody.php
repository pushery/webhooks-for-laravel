<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Captures the exact request bytes before anything downstream can parse, mutate or
 * re-encode them, and stashes them on the request. Signature verification then runs
 * over these bytes, not over a re-serialized body — the single most common webhook
 * interoperability bug. Registered early (prepended to the global stack) when
 * webhooks.client.raw_body_capture is on.
 *
 * Only a request addressed to one of the package's receiving routes is captured. The
 * middleware runs before the router has matched anything, so it asks the host's route
 * collection for the route the request reaches, the same match the router makes next,
 * and captures only when that route is a receiving route. With cached routes that match
 * runs on the compiled matcher, so no other route is built for it. A request to any other
 * address keeps its body unread here, and `RawBody::of()` reads the request's own content
 * for it, as it does with the capture switched off.
 *
 * @internal
 */
final readonly class CaptureRawBody
{
    public const string ATTRIBUTE = 'webhooks.raw_body';

    public function __construct(private Router $router) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->has(self::ATTRIBUTE) && $this->addressesAReceivingRoute($request)) {
            $request->attributes->set(self::ATTRIBUTE, $request->getContent());
        }

        return $next($request);
    }

    /** Whether the route the request reaches, by its method, host and path, is a receiving route. */
    private function addressesAReceivingRoute(Request $request): bool
    {
        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (MethodNotAllowedHttpException|NotFoundHttpException) {
            // No route takes the request, and the router answers it with 405 or 404.
            return false;
        }

        return ReceivingRoutes::isReceiving($route);
    }
}
