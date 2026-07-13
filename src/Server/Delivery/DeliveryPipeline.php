<?php

declare(strict_types=1);

namespace Webhooks\Server\Delivery;

use Illuminate\Contracts\Container\Container;
use Throwable;
use Webhooks\Core\Http\Exceptions\NonRetryable;
use Webhooks\Core\Http\HttpTransport;
use Webhooks\Core\Signing\SecretSet;
use Webhooks\Core\Signing\SignatureScheme;
use Webhooks\Core\Signing\WebhookMessage;
use Webhooks\Core\Ssrf\SsrfGuard;
use Webhooks\Server\Data\WebhookDeliveryData;
use Webhooks\Server\Exceptions\UnknownSignatureScheme;
use Webhooks\Server\Signing\SecretResolver;

/**
 * Runs a single delivery attempt end-to-end and returns a typed {@see AttemptOutcome}
 * — no queue awareness, no events, so the whole decision surface is unit-testable.
 *
 * Each attempt RE-resolves and RE-pins the destination (anti-rebind) and RE-signs
 * at send time (queue dwell never expires a legitimate signature), using the stable
 * message id so retries carry the same `webhook-id`. A blocked destination is a
 * non-retryable final failure; every transport failure — connection, timeout, TLS,
 * DNS, reset, partial transfer — is retryable, and NONE of them may escape as an
 * exception: an escaping transport error would bypass the lifecycle events that own
 * the delivery's fate and strand its log row.
 *
 * @internal
 */
final readonly class DeliveryPipeline
{
    public function __construct(
        private SsrfGuard $guard,
        private SecretResolver $secrets,
        private HttpTransport $transport,
        private ResponseClassifier $classifier,
        private Container $container,
    ) {}

    public function attempt(WebhookDeliveryData $data): AttemptOutcome
    {
        try {
            $endpoint = $this->guard->resolveAndPin($data->url);
            $headers = $this->headersFor($data);
        } catch (NonRetryable $exception) {
            return AttemptOutcome::finalFailure(null, $exception);
        }

        try {
            $response = $this->transport->send(
                $endpoint,
                $data->rawBody,
                $headers,
                $data->options->toTransportOptions(),
            );
        } catch (Throwable $exception) {
            // EVERY way the transport can fail is a retryable delivery failure — and the
            // net has to be this wide. Laravel marshals only curl's five connect-phase
            // errnos into a ConnectionException; an expired, self-signed or
            // hostname-mismatched certificate (the everyday CURLE_PEER_FAILED_VERIFICATION),
            // a connection reset mid-response, or a partial transfer all surface as a
            // plain Guzzle RequestException instead. Catching just the one type let those
            // escape the state machine entirely: no lifecycle event, a delivery row stuck
            // pending for ever, a circuit breaker that never counts the failure, and a
            // queue that re-releases with no backoff at all. A returned outcome flows
            // through the events, the log, the backoff and the breaker like any other.
            return AttemptOutcome::retryable(null, $exception);
        }

        return match ($this->classifier->classify($response)) {
            Disposition::Succeeded => AttemptOutcome::succeeded($response),
            Disposition::Retryable => AttemptOutcome::retryable($response, null),
            Disposition::FinalFailure => AttemptOutcome::finalFailure($response, null),
        };
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(WebhookDeliveryData $data): array
    {
        if ($data->doNotSign) {
            return $data->headers;
        }

        $secrets = $this->secrets->resolveFor($data);

        if (! $secrets instanceof SecretSet) {
            return $data->headers;
        }

        $scheme = $this->container->make($data->schemeClass);

        if (! $scheme instanceof SignatureScheme) {
            throw UnknownSignatureScheme::for($data->schemeClass);
        }

        $signature = $scheme->sign(WebhookMessage::for($data->rawBody, $data->messageId), $secrets);

        return array_merge($data->headers, $signature->toArray());
    }
}
