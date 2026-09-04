<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Delivery;

use Illuminate\Contracts\Container\Container;
use Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable;
use Pushery\Webhooks\Core\Http\Exceptions\NonRetryable;
use Pushery\Webhooks\Core\Http\HttpTransport;
use Pushery\Webhooks\Core\Signing\SecretSet;
use Pushery\Webhooks\Core\Signing\SignatureScheme;
use Pushery\Webhooks\Core\Signing\WebhookMessage;
use Pushery\Webhooks\Core\Ssrf\SsrfGuard;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Exceptions\UnknownSignatureScheme;
use Pushery\Webhooks\Server\Signing\SecretResolver;
use Throwable;

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
        } catch (HostUnresolvable $exception) {
            // Caught by its own type rather than by a wide net. A bare `catch (Throwable)`
            // here would also swallow the signing faults raised a line below — an unknown
            // scheme, a missing key — and turn a configuration error into a delivery that
            // retries for hours instead of saying what is wrong.
            return AttemptOutcome::retryable(null, $exception);
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
        } catch (NonRetryable $exception) {
            // The one transport failure a retry cannot bridge: the endpoint answered, and
            // what it answered contradicts itself about where the body ends. The next attempt
            // reaches the same endpoint and gets the same bytes, so retrying spends the whole
            // budget on identical refusals and feeds the circuit breaker twenty times over an
            // endpoint whose actual defect nobody was told about. Failing final says it once.
            return AttemptOutcome::finalFailure(null, $exception);
        } catch (Throwable $exception) {
            // EVERY OTHER way the transport can fail is a retryable delivery failure — and
            // the net has to be this wide. Laravel marshals only curl's five connect-phase
            // errnos into a ConnectionException; an expired, self-signed or
            // hostname-mismatched certificate (the everyday CURLE_PEER_FAILED_VERIFICATION),
            // a connection reset mid-response, or a partial transfer all surface as some
            // other Guzzle RequestException instead. Catching just the one type let those
            // escape the state machine entirely: no lifecycle event, a delivery row stuck
            // pending for ever, a circuit breaker that never counts the failure, and a
            // queue that re-releases with no backoff at all. A returned outcome flows
            // through the events, the log, the backoff and the breaker like any other.
            //
            // On guzzle 8 the reset and the partial transfer arrive as the subclass the arm above
            // matches on, which is how they once stopped reaching this line.
            // `isResponseTransferError()` covers guzzle's whole connection- and network-error
            // tables plus errnos 18 and 61 once response headers have arrived, so both events are
            // `ResponseTransferException` — the class the framing contradiction also uses. The
            // normalizer answered every one of them NonRetryable, so a receiver that reset one
            // connection lost that webhook after a single attempt. It now claims that
            // classification only where the permanent event is positively identified, which is what
            // puts these two back on this line. `TransportFramingShapeTest` drives both over a
            // socket, one arm each way.
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
