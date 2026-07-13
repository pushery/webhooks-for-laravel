<?php

declare(strict_types=1);

namespace Webhooks\Core\Http;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Webhooks\Core\Ssrf\PinnedEndpoint;

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
            ->withHeaders($headers)
            ->withBody($rawBody, $options->contentType)
            ->send($options->verb, $endpoint->url);

        $durationMs = intdiv(hrtime(true) - $start, 1_000_000);

        $psr = $response->toPsrResponse();

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
