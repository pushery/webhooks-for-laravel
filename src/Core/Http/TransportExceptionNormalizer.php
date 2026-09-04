<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Pushery\Webhooks\Core\Http\Exceptions\MalformedResponseFraming;
use RuntimeException;
use Throwable;

/**
 * Gives a transport timeout one shape across the two guzzle majors this package supports, at the
 * wire boundary and nowhere else — the same doctrine as the verb uppercasing in {@see
 * HttpTransport} and the redaction in {@see ErrorMessageRedactor}.
 *
 * The divergence, measured on both sides. A read timeout that strikes AFTER the response
 * headers have arrived is, on guzzle 7, unconditionally a response-less `ConnectException`:
 * errno 28 sits in its five-entry connection-error list and `CurlFactory` passes a literal
 * `null` for the response, so the partial response is discarded. Guzzle 8 removed 28 from
 * that list and branches on whether a response exists — with one, it raises a
 * `ResponseTimeoutException` that CARRIES it.
 *
 * Laravel then treats the two differently, and this is where it becomes visible: it asks
 * `responseFromException()` for a response and, finding one whose status is >= 400, throws
 * `$response->toException()` instead of a `ConnectionException`. The delivery row that
 * guzzle 7 fills with `cURL error 28: Operation timed out after 3002 milliseconds with 0 out
 * of 100 bytes received` gets `HTTP request returned status code 503` under guzzle 8 —
 * beside an `http_status` of NULL, because no response reaches the pipeline either way. The
 * row then contradicts itself, and the timeout diagnosis is gone.
 *
 * It is gone for good, which is why this sits at the boundary rather than in `errorFrom()`.
 * `Response::toException()` builds a fresh exception with no `previous`, so by the time the
 * listener writes the row there is nothing left to recover the errno from. Normalizing has to
 * happen before Laravel marshals, and the one hook for that is guzzle middleware.
 *
 * It also normalizes only the timeout, never its parent. `ResponseTimeoutException` is a subclass
 * of `ResponseTransferException`, which guzzle 8 also raises for a response whose framing is
 * malformed. Those are different events and want opposite treatment: a framing rejection concerns a
 * response that arrived complete, a timeout concerns one that did not. A check written one level up
 * would quietly turn an aborted transfer into a delivery carrying a truncated body.
 *
 * On guzzle 7 the whole class is a no-op by construction: `ResponseTimeoutException` does not
 * exist there, and `instanceof` against a missing class is `false` without autoloading or
 * error — measured, not assumed.
 *
 * @internal
 */
final class TransportExceptionNormalizer
{
    /**
     * The guzzle middleware that applies {@see self::normalize()} to a rejected transfer.
     *
     * A named method rather than a closure in the caller, so the handler it wraps carries a
     * real signature: given a bare `callable`, the promise is `mixed` and nothing downstream
     * can be checked at all. The option array is typed the way guzzle's own middleware types
     * it — an open map, because guzzle passes its whole request-option bag through here and
     * this code neither reads nor narrows it.
     *
     * @param  callable(RequestInterface, array<array-key, mixed>): PromiseInterface  $handler
     * @return callable(RequestInterface, array<array-key, mixed>): PromiseInterface
     */
    public static function wrap(callable $handler): callable
    {
        return
            /**
             * @param  array<array-key, mixed>  $options
             */
            // A rejection reason need not be a Throwable — guzzle only ever rejects with
            // one, but the promise contract allows anything, and `throw` needs one. Letting
            // a non-throwable fall through would raise a TypeError whose message says
            // nothing about the delivery that failed.
            static fn (RequestInterface $request, array $options): PromiseInterface => $handler($request, $options)->otherwise(static fn (mixed $reason): never => throw $reason instanceof Throwable
                ? self::normalize($reason)
                : new RuntimeException('The webhook transport was rejected with a non-throwable reason.'));
    }

    public static function normalize(Throwable $exception): Throwable
    {
        // Order is load-bearing, because guzzle files the two under one parent:
        // ResponseTimeoutException extends ResponseTransferException. Asked the other way round,
        // every timeout would be answered as a framing defect — a delivery that could have
        // succeeded on the next attempt failed final instead, and the errno went with it.
        if ($exception instanceof ResponseTimeoutException) {
            return self::withoutCarriedResponse($exception);
        }

        // A response that arrived COMPLETE and contradicts itself about where its body ends.
        // Guzzle 8 refuses it, guzzle 7 hands it back as an ordinary 200 — so the majors
        // disagree, and the shape this package gives it settles the disagreement in one place.
        //
        // Only that one event, and the narrowing is the point of the condition. This arm used to
        // answer every `ResponseTransferException`, and guzzle 8 files far more than a framing
        // contradiction under that class: `isResponseTransferError()` is true for its whole
        // connection-error and network-error tables plus errnos 18 and 61, whenever response
        // headers had already arrived. So a receiver that reset the connection or sent a short body
        // — errno 56, errno 18, both transient — was answered NonRetryable, and the delivery failed
        // final on the first attempt with its retry budget untouched. Measured end to end against a
        // socket, with a well-formed response as the control.
        //
        // That is the expensive direction. A framing contradiction retried costs a handful of extra
        // requests to a receiver that is broken anyway; a reset treated as final loses the webhook,
        // and feeds the endpoint's failure streak with events no successful delivery can
        // interleave. So the default here is Retryable, reached by falling through untouched, and
        // NonRetryable is claimed only where the permanent event is positively identified.
        if ($exception instanceof ResponseTransferException && self::isFramingContradiction($exception)) {
            return new MalformedResponseFraming($exception->getMessage(), 0, $exception);
        }

        return $exception;
    }

    /**
     * Whether this is the response that refuted itself, rather than the transfer that broke.
     *
     * Guzzle builds the two at different sites, and only one of them carries a cause:
     *
     *   EasyHandle::createResponse()  — the framing check. Constructs the exception with the
     *                                   RuntimeException psr7 raised as its `previous`.
     *   CurlFactory::createRejection() — the errno path. Passes its own `$previous`, which is
     *                                   null for an ordinary curl failure.
     *
     * Measured on both, through a real socket rather than read off the source:
     *
     *   framing    previous=RuntimeException  carriedResponse=200  bodyLen=0
     *   truncated  previous=NULL              carriedResponse=200  bodyLen=10
     *
     * The body length is the same fact from the other side and is the reason to trust the
     * discriminator rather than merely observe it: a framing rejection happens while the
     * HEADERS are parsed, so no body was ever admitted; a transfer break happens after the
     * bytes started arriving. `TransportFramingShapeTest` drives both events over a socket, so
     * a guzzle release that moves either construction turns this red instead of silently
     * reclassifying a whole class of transient failures.
     */
    private static function isFramingContradiction(ResponseTransferException $exception): bool
    {
        return $exception->getPrevious() instanceof Throwable;
    }

    /**
     * Restate a timeout that carries a partial response as the response-less shape guzzle 7
     * raises for the same wire event.
     */
    private static function withoutCarriedResponse(ResponseTimeoutException $exception): Throwable
    {
        // The response-less sibling guzzle 8 raises for the same errno when no headers had
        // arrived — so both wire events, and both majors, reach Laravel in one shape and
        // persist the same errno 28 text. The original is kept as `previous`, which is more
        // than guzzle 7 offers: there the partial response is simply dropped.
        return new NetworkTimeoutException(
            $exception->getMessage(),
            $exception->getRequest(),
            $exception,
        );
    }
}
