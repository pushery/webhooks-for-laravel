<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable;
use Pushery\Webhooks\Core\Http\Exceptions\MalformedResponseFraming;
use Pushery\Webhooks\Core\Http\Exceptions\ResponseTooLarge;
use Pushery\Webhooks\Core\Ssrf\PinnedEndpoint;
use Pushery\Webhooks\Core\Ssrf\SsrfGuard;
use Throwable;

/**
 * Fetches a URL on a server the application does not control, under the same guard and with the
 * same connection rules as a webhook delivery: an image in a received mail, a feed, a document a
 * user linked to.
 *
 * The URL is vetted by the shared {@see SsrfGuard}, and the connection is pinned to the addresses
 * it vetted, so a DNS record that changes between the check and the connect still reaches the
 * address that was checked. A redirect is handed back rather than followed, because its target
 * was never vetted. A proxy named in the environment is bypassed, because a proxy resolves the
 * host itself and the pin would not reach the connection. A compressed body is handed back as it
 * arrived and never inflated, and TLS is verified.
 *
 * The body is held to a limit the caller names. Past it the transfer ends, whether the server
 * declared a length or sends without end, and the fetch throws {@see ResponseTooLarge}. A caller
 * that needs only the status and the headers asks {@see self::lead()}, which reads no body at all.
 */
final readonly class GuardedFetch
{
    public function __construct(
        private SsrfGuard $guard,
    ) {}

    /**
     * GET a URL and hand back the response, with a body of at most `$maxBytes` bytes.
     *
     * A response comes back with whatever status it carries, a redirect with its `Location`
     * header among them. Only a refused destination, a body past the limit and a failed transfer
     * throw.
     *
     * @param  int  $maxBytes  the longest body accepted, in bytes
     * @param  array<string, string>  $headers  request headers, such as `Accept`
     * @param  int  $connectTimeout  seconds to wait for the connection
     * @param  int  $timeout  seconds the whole transfer may take
     *
     * @throws BlockedDestination when the guard refuses the URL
     * @throws HostUnresolvable when the host resolves to no address
     * @throws ResponseTooLarge when the body is longer than `$maxBytes`
     * @throws MalformedResponseFraming when the response declares its length and is chunked as well
     * @throws ConnectionException when the connection fails or a timeout strikes
     */
    public function get(string $url, int $maxBytes, array $headers = [], int $connectTimeout = 3, int $timeout = 10): FetchedResponse
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('The byte limit of a fetch is never negative.');
        }

        $endpoint = $this->guard->resolveAndPin($url);
        $sink = new LimitedSink($maxBytes);

        $options = $this->transportOptions($endpoint, $connectTimeout, $timeout) + [
            'sink' => $sink,
            'on_headers' => static function (ResponseInterface $response) use ($sink): void {
                $sink->refuseDeclaredLength($response->getHeaderLine('Content-Length'));
            },
        ];

        $start = hrtime(true);

        try {
            $response = Http::withOptions($options)
                ->withMiddleware(TransportExceptionNormalizer::wrap(...))
                ->withHeaders($headers)
                ->get($endpoint->url);
        } catch (Throwable $exception) {
            if ($sink->overflowed()) {
                throw ResponseTooLarge::over($maxBytes, $exception);
            }

            throw $exception;
        }

        $durationMs = intdiv(hrtime(true) - $start, 1_000_000);
        $psr = $response->toPsrResponse();

        $this->refuseContradictoryFraming($psr);

        return new FetchedResponse(
            status: $response->status(),
            headers: $this->headersOf($psr),
            body: $this->bodyOf($psr->getBody(), $maxBytes),
            durationMs: $durationMs,
        );
    }

    /**
     * Ask a URL where it leads: its status and its headers, never its body.
     *
     * The question goes out as a GET, the request a page answers the way it answers a browser, and
     * the transfer ends as soon as the headers are in. The body is never read, so however long it
     * is, and whether it ends at all, it costs the answer neither its status nor its time. An
     * informational answer such as `103 Early Hints` is passed over for the response it precedes.
     *
     * Everything else holds as for {@see self::get()}: the URL is vetted and the connection
     * pinned, a redirect comes back with its `Location` header instead of being followed, a proxy
     * named in the environment is bypassed, nothing is inflated, and TLS is verified.
     *
     * A plaintext `http://` URL is taken even where `webhooks.core.ssrf.https_only` holds webhook
     * endpoints to `https`. That switch is about what a webhook carries, and this request carries
     * nothing: it sends no body and reads none. The guard vets the URL in its `https` form, since
     * the host and the addresses it stands at are the same under either scheme, and the connection
     * is pinned to those addresses on the port of the plaintext URL.
     *
     * @param  array<string, string>  $headers  request headers, such as `Accept`
     * @param  int  $connectTimeout  seconds to wait for the connection
     * @param  int  $timeout  seconds the transfer may take until the headers are in
     * @return FetchedResponse its body always empty
     *
     * @throws BlockedDestination when the guard refuses the URL
     * @throws HostUnresolvable when the host resolves to no address
     * @throws MalformedResponseFraming when the response declares its length and is chunked as well
     * @throws ConnectionException when the connection fails or a timeout strikes before the headers are in
     */
    public function lead(string $url, array $headers = [], int $connectTimeout = 3, int $timeout = 10): FetchedResponse
    {
        $endpoint = $this->vetWhereItLeads($url);
        $head = new ResponseHead;

        $options = $this->transportOptions($endpoint, $connectTimeout, $timeout) + [
            'on_headers' => $head->keep(...),
        ];

        $start = hrtime(true);

        try {
            $psr = Http::withOptions($options)
                ->withMiddleware(TransportExceptionNormalizer::wrap(...))
                ->withHeaders($headers)
                ->get($endpoint->url)
                ->toPsrResponse();
        } catch (Throwable $exception) {
            // The transfer ends right after the head, by design. Without a head the failure
            // came first, and it is the answer.
            $psr = $head->response() ?? throw $exception;
        }

        $durationMs = intdiv(hrtime(true) - $start, 1_000_000);

        $this->refuseContradictoryFraming($psr);

        return new FetchedResponse(
            status: $psr->getStatusCode(),
            headers: $this->headersOf($psr),
            body: '',
            durationMs: $durationMs,
        );
    }

    /**
     * The URL of a lead, vetted and pinned. A plaintext URL is vetted in its `https` form and then
     * pinned to the same addresses on its own port, the one it names or 80.
     *
     * @throws BlockedDestination when the guard refuses the URL
     * @throws HostUnresolvable when the host resolves to no address
     */
    private function vetWhereItLeads(string $url): PinnedEndpoint
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'http') {
            return $this->guard->resolveAndPin($url);
        }

        $vetted = $this->guard->resolveAndPin('https'.substr($url, 4));

        return new PinnedEndpoint(
            url: Str::replaceStart('https://', 'http://', $vetted->url),
            host: $vetted->host,
            port: isset($parts['port']) ? $vetted->port : 80,
            scheme: 'http',
            ips: $vetted->ips,
        );
    }

    /**
     * The transport options of both requests: the timeouts, no redirect followed, TLS verified,
     * nothing inflated, no proxy, and the connection pinned to the vetted addresses.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(PinnedEndpoint $endpoint, int $connectTimeout, int $timeout): array
    {
        $options = [
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
            'allow_redirects' => false,
            'verify' => true,
            'decode_content' => false,
            'proxy' => ['no' => ['*']],
        ];

        $resolve = $endpoint->curlResolveEntries();

        if ($resolve !== []) {
            $options['curl'] = [CURLOPT_RESOLVE => $resolve];
        }

        return $options;
    }

    /**
     * The refusal the delivery transport makes, for the same reason: guzzle 8 refuses such a
     * response itself and guzzle 7 hands it back, so both answer the same here.
     *
     * @throws MalformedResponseFraming when the response declares its length and is chunked as well
     */
    private function refuseContradictoryFraming(ResponseInterface $response): void
    {
        if ($response->hasHeader('Content-Length') && $response->hasHeader('Transfer-Encoding')) {
            throw new MalformedResponseFraming('A response must not contain both Content-Length and Transfer-Encoding');
        }
    }

    /**
     * Each response header with its values, under the name the server sent.
     *
     * @return array<string, list<string>>
     */
    private function headersOf(ResponseInterface $response): array
    {
        $received = [];

        foreach ($response->getHeaders() as $name => $values) {
            $received[(string) $name] = array_values($values);
        }

        return $received;
    }

    /**
     * The body, refused here as well when it is longer than the limit: a faked response in a test
     * never passes through the sink, and the limit holds for it all the same.
     */
    private function bodyOf(StreamInterface $stream, int $maxBytes): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = $stream->getContents();

        if (strlen($body) > $maxBytes) {
            throw ResponseTooLarge::over($maxBytes);
        }

        return $body;
    }
}
