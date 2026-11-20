<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures the exact request bytes before anything downstream can parse, mutate or
 * re-encode them, and stashes them on the request. Signature verification then runs
 * over these bytes, not over a re-serialized body — the single most common webhook
 * interoperability bug. Registered early (prepended to the global stack) when
 * webhooks.client.raw_body_capture is on.
 *
 * Only a request addressed to one of the package's receiving routes is captured, told by its
 * method and its path. Not by its host: the middleware runs before every middleware of the
 * host, and a host that sets its trusted hosts for each request in one of them has not set
 * them yet, so the host read here would be checked against the list of the request before.
 * A receiving route bound to a domain is captured on any host as a result, which costs
 * nothing, as the capture takes and refuses nothing. A request to any other address keeps
 * its body unread here, and `RawBody::of()` reads the request's own content for it, as it
 * does with the capture switched off.
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

    /** Whether the request addresses a receiving route, by its method and its path, the way the router's own validators read the two. */
    private function addressesAReceivingRoute(Request $request): bool
    {
        $path = rawurldecode(rtrim($request->getPathInfo(), '/') ?: '/');

        return array_any(ReceivingRoutes::addresses($this->router), fn (array $address): bool => in_array($request->getMethod(), $address['methods'], true) && preg_match($address['path'], $path) === 1);
    }
}
