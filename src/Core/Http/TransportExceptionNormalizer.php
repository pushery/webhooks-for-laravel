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
 * Gives a transport timeout ONE shape across the two guzzle majors this package supports,
 * at the wire boundary and nowhere else — the same doctrine as the verb uppercasing in
 * {@see HttpTransport} and the redaction in {@see ErrorMessageRedactor}.
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
 * ⚠️ IT IS GONE FOR GOOD, WHICH IS WHY THIS SITS AT THE BOUNDARY AND NOT IN `errorFrom()`.
 * `Response::toException()` builds a FRESH exception with no `previous`, so by the time the
 * listener writes the row there is nothing left to recover the errno from. Normalizing has
 * to happen before Laravel marshals, and the one hook for that is guzzle middleware.
 *
 * ⚠️ AND IT NORMALIZES ONLY THE TIMEOUT, NEVER ITS PARENT. `ResponseTimeoutException` is a
 * subclass of `ResponseTransferException`, which guzzle 8 also raises for a response whose
 * framing is malformed. Those are different events and want opposite treatment: a framing
 * rejection concerns a response that arrived complete, a timeout concerns one that did not.
 * A check written one level up would quietly turn an aborted transfer into a delivery
 * carrying a truncated body.
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
        // ⚠️ ORDER IS LOAD-BEARING, because guzzle files the two under one parent:
        // ResponseTimeoutException EXTENDS ResponseTransferException. Asked the other way
        // round, every timeout would be answered as a framing defect — a delivery that could
        // have succeeded on the next attempt failed final instead, and the errno went with it.
        if ($exception instanceof ResponseTimeoutException) {
            return self::withoutCarriedResponse($exception);
        }

        // A response that arrived COMPLETE and contradicts itself about where its body ends.
        // Guzzle 8 refuses it, guzzle 7 hands it back as an ordinary 200 — so the majors
        // disagree, and the shape this package gives it settles the disagreement in one place.
        if ($exception instanceof ResponseTransferException) {
            return new MalformedResponseFraming($exception->getMessage(), 0, $exception);
        }

        return $exception;
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
