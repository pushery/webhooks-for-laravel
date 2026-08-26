<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Pushery\Webhooks\Core\Http\Exceptions\MalformedResponseFraming;
use Pushery\Webhooks\Core\Ssrf\PinnedEndpoint;

/**
 * Sends the exact signed bytes to a vetted, IP-pinned destination over Laravel's
 * HTTP client. It never follows redirects (an open-redirect is an SSRF vector),
 * keeps TLS verification on, and pins curl to the guard's vetted IPs (anti-rebind)
 * on a DIRECT connection — the pin does not reach through an egress proxy, which
 * resolves the host itself, so a proxied delivery relies on the operator's proxy to
 * enforce egress control. It applies separate connect/total timeouts, an optional
 * egress proxy and mutual TLS, and captures the response up to a byte cap without ever
 * buffering an unbounded body: the response is neither decoded nor kept beyond the cap
 * ({@see CappedSink}), so a tenant-supplied endpoint cannot answer a delivery with a
 * decompression bomb or an endless stream and take a worker's memory with it.
 *
 * It is intentionally signing- and payload-agnostic: it receives the raw body and
 * the header map already prepared by the caller.
 *
 * @internal
 */
final class HttpTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(PinnedEndpoint $endpoint, string $rawBody, array $headers, TransportOptions $options): TransportResponse
    {
        $start = hrtime(true);

        $response = Http::withOptions($this->optionsFor($endpoint, $options))
            // ⚠️ NORMALIZED HERE, BEFORE LARAVEL MARSHALS, because afterwards the information
            // is destroyed: Response::toException() builds a fresh exception with no
            // `previous`, so the errno cannot be recovered further down. See
            // {@see TransportExceptionNormalizer} for what diverges and why only the timeout
            // is touched. A no-op on guzzle 7, where the class it looks for does not exist.
            ->withMiddleware(TransportExceptionNormalizer::wrap(...))
            ->withHeaders($headers)
            ->withBody($rawBody, $options->contentType)
            // ⚠️ UPPERCASED HERE, AT THE WIRE, AND NOWHERE ELSE. The package canonicalizes
            // every verb to lowercase on the way in — PendingWebhook::useHttpVerb(),
            // Settings::httpVerb(), the config default — because that is the form it stores
            // and displays. RFC 9110 methods are CASE-SENSITIVE, so lowercase is not a
            // spelling of the method, it is a different method.
            //
            // Until now nothing here uppercased it, and nothing had to: guzzlehttp/psr7 2 did
            // it silently inside Request::__construct (`$this->method = Utils::asciiToUpper()`).
            // psr7 3 stores the method verbatim, so on that version every delivery this package
            // makes would go out as `post /hook HTTP/1.1` — which a strict server is right to
            // refuse, and PHP's built-in server refuses by closing the socket without a reply.
            //
            // The fix belongs at this one boundary rather than in the four places that
            // lowercase: the stored form is deliberate and readers depend on it. Under psr7 2
            // this is a no-op, so it holds for both majors.
            ->send(strtoupper($options->verb), $endpoint->url);

        $durationMs = intdiv(hrtime(true) - $start, 1_000_000);

        $psr = $response->toPsrResponse();

        // ⚠️ THE SAME REFUSAL GUZZLE 8 MAKES, MADE HERE, BECAUSE GUZZLE 7 DOES NOT MAKE IT.
        // A response carrying both `Content-Length` and `Transfer-Encoding` contradicts itself
        // about where its body ends; RFC 9112 §6.1 forbids it outright. Guzzle 8 validates the
        // framing before the response is ever visible and rejects the transfer — measured —
        // while guzzle 7 has no such check and hands back an ordinary 200 with both headers
        // still on it. Without this, widening the constraint would have made the SAME wire
        // event a delivered webhook on one major and a failed delivery on the other.
        //
        // Guzzle 8 never reaches this line for such a response: its rejection arrives as an
        // exception that TransportExceptionNormalizer turns into the same class. Two routes,
        // one outcome, and the one a host sees is the outcome.
        if ($psr->hasHeader('Content-Length') && $psr->hasHeader('Transfer-Encoding')) {
            throw new MalformedResponseFraming(
                'A response must not contain both Content-Length and Transfer-Encoding',
            );
        }

        [$body, $truncated] = $this->captureBody($psr->getBody(), $options->responseCaptureBytes);

        $headers = [];

        foreach ($psr->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return new TransportResponse(
            status: $response->status(),
            headers: $headers,
            body: $body,
            truncated: $truncated,
            durationMs: $durationMs,
        );
    }

    /**
     * The Guzzle option set for one attempt. Public so the exact option construction
     * (no-redirect, TLS verify, IP pin, timeouts, proxy, mTLS) is unit-testable.
     *
     * @return array<string, mixed>
     */
    public function optionsFor(PinnedEndpoint $endpoint, TransportOptions $options): array
    {
        $guzzle = [
            'connect_timeout' => $options->connectTimeout,
            'timeout' => $options->timeout,
            'allow_redirects' => false,
            'verify' => $options->verify,

            // Do not advertise (or transparently inflate) a compressed response. A webhook
            // endpoint's answer is a receipt, not a document: nothing here needs decoding,
            // and asking for gzip hands a tenant-supplied endpoint the ability to expand a
            // few kilobytes on the wire into gigabytes in this process — the classic
            // decompression bomb — before a single byte cap could be applied.
            'decode_content' => false,

            // Keep only the capture prefix of the body. Guzzle's default sink buffers the
            // WHOLE response (spilling to a temp file past 2 MB) and would leave the cap to
            // trim what is already materialized, which bounds the log entry but not the
            // download.
            'sink' => new CappedSink($options->responseCaptureBytes),
        ];

        if ($options->proxy !== null) {
            $guzzle['proxy'] = $options->proxy;
        }

        if ($options->clientCert !== null) {
            $guzzle['cert'] = $options->clientCertPassphrase !== null
                ? [$options->clientCert, $options->clientCertPassphrase]
                : $options->clientCert;
        }

        if ($options->clientKey !== null) {
            $guzzle['ssl_key'] = $options->clientKey;
        }

        $resolve = $endpoint->curlResolveEntries();

        if ($resolve !== []) {
            $guzzle['curl'] = [CURLOPT_RESOLVE => $resolve];
        }

        return $guzzle;
    }

    /**
     * Read at most $cap bytes of the response, reporting truncation when there was
     * more. It reads ONE byte past the cap and keeps the cap: that is what separates
     * "exactly the cap" from "more than the cap" without ever holding more than the
     * cap plus a byte.
     *
     * This trims what the {@see CappedSink} has already bounded — the sink is what
     * keeps a hostile body from being downloaded into memory or a temp file in the
     * first place; this is what the delivery log stores.
     *
     * @return array{0: string, 1: bool}
     */
    private function captureBody(StreamInterface $stream, int $cap): array
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        $limit = $cap + 1;

        while (! $stream->eof() && strlen($body) < $limit) {
            $chunk = $stream->read($limit - strlen($body));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return strlen($body) > $cap ? [substr($body, 0, $cap), true] : [$body, false];
    }
}
