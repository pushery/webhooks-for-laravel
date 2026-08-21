<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Illuminate\Http\Request;
use Pushery\Webhooks\Client\Verification\InboundVerifier;

/**
 * The exact bytes of an inbound delivery, for code that has to verify over them.
 *
 * A signature scheme is handed the body already. An {@see InboundVerifier}
 * is not — it receives the Request — and at least one of the two cases that seam exists for
 * NEEDS the bytes: a provider that verifies through a callback API checks the document it
 * sent, so the verifier has to send back exactly that document.
 *
 * Re-encoding is where this goes wrong, and it goes wrong quietly. `$request->json()->all()`
 * followed by `json_encode` is not the delivery: `/` comes back as `\/`, non-ASCII as
 * `\uXXXX`, and key order is not guaranteed to survive. The provider then answers about a
 * DIFFERENT document — with HTTP 200 and a negative verdict. Nothing throws, nothing logs,
 * and no test goes red while the provider is faked. In production it reads as "the provider
 * rejects our webhooks", with no cause anywhere.
 *
 * So: a verifier that authenticates over bytes must use this and must never use
 * `$request->json()`.
 *
 * Reading the request's own content is the fallback, not the primary source. When
 * `webhooks.client.raw_body_capture` is on, {@see CaptureRawBody} stashes the bytes before
 * anything downstream can parse, mutate or re-encode them; that stash is what makes this
 * exact even behind middleware that rewrites the body.
 */
final class RawBody
{
    /**
     * The captured bytes if the middleware ran, otherwise the request's own content.
     *
     * The same resolution the package's own inbound processor uses — deliberately shared with
     * it rather than restated, so a verifier and the pipeline can never disagree about what
     * "the body" was for one delivery.
     */
    public static function of(Request $request): string
    {
        $captured = $request->attributes->get(CaptureRawBody::ATTRIBUTE);

        return is_string($captured) ? $captured : $request->getContent();
    }
}
