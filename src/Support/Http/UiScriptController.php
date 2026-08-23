<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support\Http;

use Illuminate\Http\Request;
use Pushery\Webhooks\Support\UiAssets;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the package's one JavaScript file from the application's own origin, so the screens
 * it drives work under a strict `script-src 'self'` with no nonce and nothing to configure.
 *
 * Immutable caching, keyed by the content hash the URL carries: the same URL can only ever
 * return the same bytes, because a changed file changes the hash and therefore the URL. That
 * is what makes `immutable` honest here rather than a promise the next release breaks.
 *
 * @internal
 */
final class UiScriptController
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $response = new BinaryFileResponse(UiAssets::file(), headers: [
            // text/javascript, not application/javascript: the former is the one WHATWG
            // declares and every browser accepts, and a mismatched type is refused outright
            // under X-Content-Type-Options: nosniff, which a host running a strict CSP has.
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);

        // The content hash, so a conditional request costs a 304 rather than the file. The
        // weak flag is wrong here — these bytes are byte-for-byte identical or they are a
        // different URL.
        $response->setEtag(UiAssets::version(), weak: false);
        $response->isNotModified($request);

        return $response;
    }
}
