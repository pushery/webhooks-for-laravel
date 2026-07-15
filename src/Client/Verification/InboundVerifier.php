<?php

declare(strict_types=1);

namespace Webhooks\Client\Verification;

use Illuminate\Http\Request;
use Webhooks\Client\WebhookConfig;
use Webhooks\Core\Signing\VerificationResult;

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
 * any other result rejects it with the config's invalid_status and stores nothing. Treat
 * a failed provider callback (a timeout, a 5xx) as NOT valid — never let an unreachable
 * provider turn the endpoint into an open write surface.
 */
interface InboundVerifier
{
    public function verify(Request $request, WebhookConfig $config): VerificationResult;
}
