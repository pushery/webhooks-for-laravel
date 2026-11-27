<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Pushery\Webhooks\Client\Events\InvalidWebhookSignature;
use Pushery\Webhooks\Client\Exceptions\InboundListenerFailed;
use Pushery\Webhooks\Client\Exceptions\SignedRequestRefused;
use Pushery\Webhooks\Client\Exceptions\WebhookConfigCannotVerify;
use Pushery\Webhooks\Client\Tenancy\InboundTenantResolver;
use Pushery\Webhooks\Client\Verification\InboundVerifier;
use Pushery\Webhooks\Client\Verification\VerifiesSignedRequests;
use Pushery\Webhooks\Client\WebhookConfig;
use Pushery\Webhooks\Core\Signing\Exceptions\InvalidMessage;
use Pushery\Webhooks\Core\Signing\IdentifiesSignedMessages;
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
 * A call that verified is remembered in the application's cache, per config and tenant, under the key its scheme names
 * from what the signature covers ({@see IdentifiesSignedMessages}), for as long as the signature could verify. The
 * same call a second time is refused. A scheme that signs no timestamp names no key, and a `verifier` is not asked for
 * one, so a route behind either refuses a repeated call itself. A cache that does not answer lets the call through,
 * and so does a store that keeps nothing.
 *
 * A refused call is answered with the config's `invalid_status`, with its `undetermined_status` when the check could
 * not complete, or with its `replayed_status` when the call had arrived before, and announced with
 * {@see InvalidWebhookSignature}, as a refused delivery is. It never reaches the route. The answer is a
 * {@see SignedRequestRefused}, an HttpException with that status, which the application renders as it renders any
 * other unless it shapes the answer to a refused call itself.
 */
final readonly class VerifySignedRequest
{
    /**
     * How long past twice the tolerance a call is remembered: two servers that verify calls for the same application
     * can read the window a little apart on their clocks.
     */
    private const int CLOCK_SLACK_SECONDS = 60;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $config): Response
    {
        try {
            $entry = WebhookConfig::forName($config);
            [$result, $tenant, $messageKey] = $this->verdict($request, $entry);
        } catch (WebhookConfigCannotVerify $cannotVerify) {
            // The answer the webhook endpoint gives a config that cannot verify: a refusal, never a 500, and reported as
            // the configuration fault it is.
            $this->reportWithoutFailing($cannotVerify);

            throw SignedRequestRefused::unverifiable($cannotVerify);
        }

        if (! $result->isValid()) {
            $this->announce($config, $result->reason(), $request, $tenant);

            throw SignedRequestRefused::because(
                $config,
                $result,
                $result->status === VerificationStatus::Undetermined ? $entry->undeterminedStatus() : $entry->invalidStatus(),
            );
        }

        if ($messageKey !== null && ! $this->arrivesFirst($entry, $tenant, $messageKey)) {
            $this->announce($config, SignedRequestRefused::REPLAYED, $request, $tenant);

            throw SignedRequestRefused::replayed($config, $entry->replayedStatus());
        }

        return $next($request);
    }

    /**
     * The verdict over the call, the key of the tenant it names when the config resolves one, and the key of the
     * message its scheme names when the call verified.
     *
     * @return array{VerificationResult, ?string, ?string}
     *
     * @throws WebhookConfigCannotVerify when the config's verifier checks the body alone
     */
    private function verdict(Request $request, WebhookConfig $config): array
    {
        try {
            $signed = SignedRequest::content($request->getMethod(), $request->getRequestUri(), RawBody::of($request));
        } catch (InvalidMessage) {
            // A target with whitespace in it was not sent by a client that signs, so there is nothing to verify.
            return [VerificationResult::malformed(), null, null];
        }

        $verifier = $config->verifier();

        if ($verifier instanceof InboundVerifier) {
            if (! $verifier instanceof VerifiesSignedRequests) {
                throw WebhookConfigCannotVerify::verifierReadsOnlyTheBody($config->name, $verifier::class, $config->invalidStatus());
            }

            return [$this->askedOf($verifier, $request, $config, $signed), null, null];
        }

        try {
            $tenants = $config->tenantResolver();
            $tenant = $tenants?->resolve($request, $config);
            $secrets = $tenants instanceof InboundTenantResolver ? $tenant?->secrets() : $config->secrets();
        } catch (Throwable $lookupFailure) {
            // A tenant record or a key set that did not answer: the check could not complete.
            $this->reportWithoutFailing($lookupFailure);

            return [VerificationResult::undetermined(), null, null];
        }

        if (! $secrets instanceof SecretSet) {
            // The call names no tenant this installation knows, so no secret could have signed it.
            return [VerificationResult::invalid(), null, null];
        }

        $scheme = $config->scheme();
        $headers = $this->headers($request);
        $result = $scheme->verify($signed, $headers, $secrets, $config->tolerance());

        return [
            $result,
            $tenant?->key,
            $result->isValid() && $scheme instanceof IdentifiesSignedMessages ? $scheme->signedMessageKey($signed, $headers) : null,
        ];
    }

    /**
     * Whether no call with this key arrived before for the config and tenant while its signature could verify, recorded
     * in the same step, so that of two calls arriving together only one is the first.
     *
     * A scheme accepts a timestamp within the tolerance on either side of this server's clock, so a call can verify for
     * up to twice the tolerance after it first arrived, and the key is held that long. A cache that does not answer
     * lets the call through, because refusing every call while the store is down would take the API down with it. That
     * is not reported, for the reason the deliveries give for their own cache: every call during an outage would report
     * it again.
     *
     * A store that keeps nothing, such as the `null` store, answers every add with false, the first one included. So
     * a refused add counts as an earlier arrival only when the key is in the store, and on such a store every call
     * goes through, as it does while a cache does not answer.
     */
    private function arrivesFirst(WebhookConfig $config, ?string $tenant, string $messageKey): bool
    {
        $key = $this->arrivalKey($config->name, $tenant, $messageKey);
        $seconds = 2 * max(0, $config->tolerance()) + self::CLOCK_SLACK_SECONDS;

        try {
            return Cache::add($key, true, $seconds) || ! Cache::has($key);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The cache key of a call that verified. Each part carries its length, so no two triples share a key, and the
     * whole is hashed, so the key stays within the length every cache store accepts.
     */
    private function arrivalKey(string $config, ?string $tenant, string $messageKey): string
    {
        $tenantKey = $tenant ?? '';

        return 'webhooks:signed-call:'.hash('sha256', strlen($config).":{$config}:".strlen($tenantKey).":{$tenantKey}:{$messageKey}");
    }

    /**
     * Announced, and a listener that throws does not change the answer.
     */
    private function announce(string $config, string $reason, Request $request, ?string $tenant): void
    {
        try {
            InvalidWebhookSignature::dispatch($config, $reason, $request->ip(), $request->path(), $request->userAgent(), $tenant);
        } catch (Throwable $listenerFailure) {
            // A listener that throws must not turn the refusal of an anonymous caller into a 500.
            $this->reportWithoutFailing(InboundListenerFailed::for('invalid-signature', $config, $listenerFailure));
        }
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
