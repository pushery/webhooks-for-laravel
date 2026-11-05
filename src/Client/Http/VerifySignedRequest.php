<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Closure;
use Illuminate\Http\Request;
use Pushery\Webhooks\Client\Events\InvalidWebhookSignature;
use Pushery\Webhooks\Client\Exceptions\InboundListenerFailed;
use Pushery\Webhooks\Client\Exceptions\SignedRequestRefused;
use Pushery\Webhooks\Client\Exceptions\WebhookConfigCannotVerify;
use Pushery\Webhooks\Client\Tenancy\InboundTenantResolver;
use Pushery\Webhooks\Client\Verification\InboundVerifier;
use Pushery\Webhooks\Client\Verification\VerifiesSignedRequests;
use Pushery\Webhooks\Client\WebhookConfig;
use Pushery\Webhooks\Core\Signing\Exceptions\InvalidMessage;
use Pushery\Webhooks\Core\Signing\SecretSet;
use Pushery\Webhooks\Core\Signing\SignatureHeaders;
use Pushery\Webhooks\Core\Signing\SignedRequest;
use Pushery\Webhooks\Core\Signing\SignsRequests;
use Pushery\Webhooks\Core\Signing\VerificationResult;
use Pushery\Webhooks\Core\Signing\VerificationStatus;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refuses a call to a route of the application's own API unless it is signed over its method, its target and its
 * body, the content {@see SignedRequest} describes and {@see SignsRequests} signs.
 *
 *     Route::post('/holds/{hold}/release', ReleaseHold::class)
 *         ->middleware(VerifySignedRequest::class.':partner');
 *
 * The parameter names a config in `webhooks.client.configs`, and the config verifies the call the way it verifies a
 * delivery: with its `secret`, its `jwks` keys or the secrets its `tenant_resolver` names, under its `scheme` and its
 * `tolerance`. A `verifier` takes part only when it implements {@see VerifiesSignedRequests}. The target is the path and
 * the query as the application receives them, so a proxy that rewrites the path on the way makes every call fail.
 *
 * A refused call is answered with the config's `invalid_status`, or with its `undetermined_status` when the check could
 * not complete, and announced with {@see InvalidWebhookSignature}, as a refused delivery is. It never reaches the route.
 * The answer is a {@see SignedRequestRefused}, an HttpException with that status, which the application renders as it
 * renders any other unless it shapes the answer to a refused call itself.
 */
final readonly class VerifySignedRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $config): Response
    {
        try {
            $entry = WebhookConfig::forName($config);
            [$result, $tenant] = $this->verdict($request, $entry);
        } catch (WebhookConfigCannotVerify $cannotVerify) {
            // The answer the webhook endpoint gives a config that cannot verify: a refusal, never a 500, and reported as
            // the configuration fault it is.
            $this->reportWithoutFailing($cannotVerify);

            throw SignedRequestRefused::unverifiable($cannotVerify);
        }

        if (! $result->isValid()) {
            try {
                InvalidWebhookSignature::dispatch($config, $result->reason(), $request->ip(), $request->path(), $request->userAgent(), $tenant);
            } catch (Throwable $listenerFailure) {
                // A listener that throws must not turn the refusal of an anonymous caller into a 500.
                $this->reportWithoutFailing(InboundListenerFailed::for('invalid-signature', $config, $listenerFailure));
            }

            throw SignedRequestRefused::because(
                $config,
                $result,
                $result->status === VerificationStatus::Undetermined ? $entry->undeterminedStatus() : $entry->invalidStatus(),
            );
        }

        return $next($request);
    }

    /**
     * The verdict over the call, and the key of the tenant it names when the config resolves one.
     *
     * @return array{VerificationResult, ?string}
     *
     * @throws WebhookConfigCannotVerify when the config's verifier checks the body alone
     */
    private function verdict(Request $request, WebhookConfig $config): array
    {
        try {
            $signed = SignedRequest::content($request->getMethod(), $request->getRequestUri(), RawBody::of($request));
        } catch (InvalidMessage) {
            // A target with whitespace in it was not sent by a client that signs, so there is nothing to verify.
            return [VerificationResult::malformed(), null];
        }

        $verifier = $config->verifier();

        if ($verifier instanceof InboundVerifier) {
            if (! $verifier instanceof VerifiesSignedRequests) {
                throw WebhookConfigCannotVerify::verifierReadsOnlyTheBody($config->name, $verifier::class, $config->invalidStatus());
            }

            return [$this->askedOf($verifier, $request, $config, $signed), null];
        }

        try {
            $tenants = $config->tenantResolver();
            $tenant = $tenants?->resolve($request, $config);
            $secrets = $tenants instanceof InboundTenantResolver ? $tenant?->secrets() : $config->secrets();
        } catch (Throwable $lookupFailure) {
            // A tenant record or a key set that did not answer: the check could not complete.
            $this->reportWithoutFailing($lookupFailure);

            return [VerificationResult::undetermined(), null];
        }

        if (! $secrets instanceof SecretSet) {
            // The call names no tenant this installation knows, so no secret could have signed it.
            return [VerificationResult::invalid(), null];
        }

        return [$config->scheme()->verify($signed, $this->headers($request), $secrets, $config->tolerance()), $tenant?->key];
    }

    /**
     * The verifier's answer, or undetermined when what it asks did not answer.
     */
    private function askedOf(VerifiesSignedRequests $verifier, Request $request, WebhookConfig $config, string $signed): VerificationResult
    {
        try {
            return $verifier->verifyOver($request, $config, $signed);
        } catch (Throwable $verificationFailure) {
            $this->reportWithoutFailing($verificationFailure);

            return VerificationResult::undetermined();
        }
    }

    /**
     * The first value of each header of the request, by its name.
     */
    private function headers(Request $request): SignatureHeaders
    {
        $flat = [];

        foreach ($request->headers->all() as $name => $values) {
            $flat[$name] = (string) ($values[0] ?? '');
        }

        return SignatureHeaders::from($flat);
    }

    /**
     * Reported, and a reporter that throws does not change the answer.
     */
    private function reportWithoutFailing(Throwable $failure): void
    {
        try {
            report($failure);
        } catch (Throwable) {
            // Nothing above this can report, and the refusal still answers.
        }
    }
}
