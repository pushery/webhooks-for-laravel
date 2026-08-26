<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http\Exceptions;

use Pushery\Webhooks\Core\Http\TransportExceptionNormalizer;
use RuntimeException;

/**
 * The endpoint answered with a response whose framing contradicts itself — `Content-Length`
 * alongside `Transfer-Encoding`, which RFC 9112 §6.1 forbids outright because the two
 * disagree about where the body ends.
 *
 * It is {@see NonRetryable}, and that is the whole decision this class carries. Guzzle 8
 * refuses such a response before it is ever visible; guzzle 7 has no such check and hands it
 * back as an ordinary 200. Retrying does not bridge that: the same endpoint answers the same
 * way every time, so a retryable classification spends the delivery's whole budget on twenty
 * identical refusals and only then gives up — while the circuit breaker counts every one of
 * them and eventually switches off an endpoint whose real defect nobody was told about.
 *
 * Failing final says the true thing once: this destination cannot be delivered to as it is
 * configured, and the message names why.
 *
 * ⚠️ A TIMEOUT IS NOT THIS, EVEN THOUGH GUZZLE FILES IT UNDER THE SAME PARENT.
 * `ResponseTimeoutException` is a subclass of the exception this is built from, and it means
 * the opposite thing: a response that never finished arriving, which the very next attempt
 * may well complete. {@see TransportExceptionNormalizer} keeps
 * the two apart, and a test pins that boundary so widening the check fails rather than ships.
 */
final class MalformedResponseFraming extends RuntimeException implements NonRetryable {}
