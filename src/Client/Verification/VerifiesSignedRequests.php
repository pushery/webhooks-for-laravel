<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Verification;

use Illuminate\Http\Request;
use Pushery\Webhooks\Client\Http\VerifySignedRequest;
use Pushery\Webhooks\Client\WebhookConfig;
use Pushery\Webhooks\Core\Signing\SignedRequest;
use Pushery\Webhooks\Core\Signing\VerificationResult;

/**
 * A verifier that checks a call over the content it was signed over, not only over the body of a delivery.
 *
 * {@see VerifySignedRequest} hands it that content, built by {@see SignedRequest::content()} from the method, the target
 * and the body of the request. A verifier that reads secrets of its own, one per connection for instance, checks that
 * content where it would check the body. A config whose `verifier` does not implement this cannot guard a route of
 * {@see VerifySignedRequest}: checking the body alone would accept the signature of one call for every other address.
 */
interface VerifiesSignedRequests extends InboundVerifier
{
    /**
     * The verdict over $signed, the content the call is signed over, in place of the body.
     *
     * Return {@see VerificationResult::valid()} only when the call is proven authentic. A check that could not
     * complete, a database or a provider that did not answer, may throw or return
     * {@see VerificationResult::undetermined()}, and is refused with the config's `undetermined_status`.
     */
    public function verifyOver(Request $request, WebhookConfig $config, string $signed): VerificationResult;
}
