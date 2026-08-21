<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Verification;

use Illuminate\Http\Request;
use Pushery\Webhooks\Client\Http\RawBody;
use Pushery\Webhooks\Client\WebhookConfig;
use Pushery\Webhooks\Core\Signing\VerificationResult;

/**
 * Authenticates an inbound delivery when authenticity is NOT a pure comparison over the
 * bytes — the case a signature scheme (a pure function of
 * body + headers + secret) cannot express:
 *
 *   - Mollie signs nothing; authenticity is an authenticated API call back to the
 *     provider (`GET /payments/{id}`) — I/O, not a hash.
 *   - PayPal verifies through a cert-chain API (OAuth2) and its credential is a webhook
 *     ID, not a shared secret.
 *
 * A verifier is container-resolved, so it may depend on an HTTP client, API credentials
 * or a cache. Configure it with `'verifier' => YourVerifier::class`; it takes precedence
 * over `'scheme'`, and `'secret'` becomes optional. Everything after verification — rate
 * limiting, dedupe, storage, job dispatch, the 401-and-store-nothing path — is unchanged,
 * which is the whole point: only the authenticity predicate differs per provider.
 *
 * Return {@see VerificationResult::valid()} only when the delivery is proven authentic;
 * any other result rejects it and stores nothing. Never let an unreachable provider turn
 * the endpoint into an open write surface.
 *
 * A failed callback still refuses the delivery — but report it as
 * {@see VerificationResult::undetermined()} rather than invalid, because the two are
 * opposite events wearing the same face. A provider answering 404 says the payment never
 * existed: the delivery is a forgery, and repeated ones are someone probing the endpoint. A
 * provider that times out says nothing at all about this delivery, which was in all
 * likelihood genuine. Collapsed into one status, an outage and an attack are
 * indistinguishable to every listener, and a receiver either alerts on both or on neither.
 *
 * The distinction also reaches the sender, if the host asks for it: `undetermined_status`
 * answers that one case separately (503, say) so a producer is told to try again instead of
 * to give up. Left unset it falls back to `invalid_status` and nothing changes.
 *
 * If your verifier authenticates over the BYTES — PayPal's verify call does, since it checks
 * the document it sent — read them with {@see RawBody::of()} and never
 * with `$request->json()`. A parsed-and-re-encoded body is a different document: `/` becomes
 * `\/`, non-ASCII becomes `\uXXXX`, and key order need not survive. The provider then answers
 * about that other document, with HTTP 200 and a negative verdict — nothing throws, nothing
 * logs, and no test goes red while the provider is faked.
 */
interface InboundVerifier
{
    public function verify(Request $request, WebhookConfig $config): VerificationResult;
}
