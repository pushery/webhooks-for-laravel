<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable;
use Pushery\Webhooks\Core\Http\Exceptions\MalformedResponseFraming;
use Pushery\Webhooks\Core\Http\Exceptions\ResponseTooLarge;
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
 * declared a length or sends without end, and the fetch throws {@see ResponseTooLarge}.
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

        $options = [
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
            'allow_redirects' => false,
            'verify' => true,
            'decode_content' => false,
            'proxy' => ['no' => ['*']],
            'sink' => $sink,
            'on_headers' => static function (ResponseInterface $response) use ($sink): void {
                $sink->refuseDeclaredLength($response->getHeaderLine('Content-Length'));
            },
        ];

        $resolve = $endpoint->curlResolveEntries();

        if ($resolve !== []) {
            $options['curl'] = [CURLOPT_RESOLVE => $resolve];
        }

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

        // The refusal the delivery transport makes, for the same reason: guzzle 8 refuses such a
        // response itself and guzzle 7 hands it back, so both answer the same here.
        if ($psr->hasHeader('Content-Length') && $psr->hasHeader('Transfer-Encoding')) {
            throw new MalformedResponseFraming('A response must not contain both Content-Length and Transfer-Encoding');
        }

        $received = [];

        foreach ($psr->getHeaders() as $name => $values) {
            $received[(string) $name] = array_values($values);
        }

        return new FetchedResponse(
            status: $response->status(),
            headers: $received,
            body: $this->bodyOf($psr->getBody(), $maxBytes),
            durationMs: $durationMs,
        );
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
