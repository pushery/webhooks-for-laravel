<?php

declare(strict_types=1);

namespace Webhooks\Client\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures the exact request bytes before anything downstream can parse, mutate or
 * re-encode them, and stashes them on the request. Signature verification then runs
 * over these bytes, not over a re-serialized body — the single most common webhook
 * interoperability bug. Registered early (prepended to the global stack) when
 * webhooks.client.raw_body_capture is on.
 *
 * @internal
 */
final class CaptureRawBody
{
    public const string ATTRIBUTE = 'webhooks.raw_body';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, $request->getContent());
        }

        return $next($request);
    }
}
