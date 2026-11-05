<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable;
use Pushery\Webhooks\Core\Http\Exceptions\MalformedResponseFraming;
use Pushery\Webhooks\Core\Http\Exceptions\ResponseTimedOut;
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
 * was never vetted; {@see self::follow()} follows a chain of them, vetting and pinning each
 * station as the first. A proxy named in the environment is bypassed, because a proxy resolves the
 * host itself and the pin would not reach the connection. A compressed body is handed back as it
 * arrived and never inflated, and TLS is verified.
 *
 * The body is held to a limit the caller names. Past it the transfer ends, whether the server
 * declared a length or sends without end, and the fetch throws {@see ResponseTooLarge}. A caller
 * that needs only the status and the headers asks {@see self::lead()}, which reads no body at all.
 *
 * A timeout that strikes after the server accepted the connection throws {@see ResponseTimedOut},
 * a `ConnectionException`, so a caller can tell a slow answer from a connection that never came
 * about, which stays a plain `ConnectionException`.
 */
final readonly class GuardedFetch
{
    /** The statuses that send the client on to their `Location`. */
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private SsrfGuard $guard,
    ) {}

    /**
     * Follow where a URL leads, each redirect vetted and pinned as the first request is, and hand back
     * every station.
     *
     * Every station is a GET: a 303 asks for a GET by definition, and a 307 or a 308, which keep the
     * method, keep this one. Without `$maxBytes` each station is asked through {@see self::lead()},
     * which reads no body and takes a plaintext URL; with it, through {@see self::get()} under that
     * limit, so the last response carries its body, under the guard's rules for a URL, `https_only`
     * included.
     *
     * A 301, 302, 303, 307 or 308 with a `Location` is followed. The target is resolved against the
     * URL that answered, a relative and a protocol-relative one included, its fragment dropped, and
     * the chain goes on to it only where it is an `http` or `https` URL with a host, no plaintext
     * after `https` unless `$allowDowngrade` says so, within `$scope` of the first host, no URL the
     * chain reached before, fewer than `$maxRedirects` redirects followed so far, and a destination
     * the guard lets through. Otherwise the chain ends at that redirect, with the reason and the
     * target in the chain rather than thrown: a chain that runs into the guard is an answer about
     * the site, not a failure of the fetch.
     *
     * @param  int  $maxRedirects  the most redirects followed, 0 for none
     * @param  ?int  $maxBytes  the longest body accepted at each station, or null to read none
     * @param  array<string, string>  $headers  request headers sent to every station
     *
     * @throws BlockedDestination when the guard refuses the first URL
     * @throws HostUnresolvable when the first URL's host resolves to no address
     * @throws ResponseTooLarge when a body is longer than `$maxBytes`
     * @throws MalformedResponseFraming when a response declares its length and is chunked as well
     * @throws ResponseTimedOut when a server accepted the connection and its answer took longer than `$timeout`
     * @throws ConnectionException when a connection fails, or a timeout strikes while it is made
     */
    public function follow(string $url, int $maxRedirects = 5, ?int $maxBytes = null, RedirectScope $scope = RedirectScope::Anywhere, bool $allowDowngrade = false, array $headers = [], int $connectTimeout = 3, int $timeout = 10): FetchedChain
    {
        if ($maxRedirects < 0) {
            throw new InvalidArgumentException('The redirect limit of a follow is never negative.');
        }

        $first = strtolower((string) parse_url($url, PHP_URL_HOST));
        $response = $this->station($url, $maxBytes, $headers, $connectTimeout, $timeout);
        $hops = [new RedirectHop($url, $response->status, $response->header('Location'), $response->durationMs)];
        // Keyed the way a target is written, so a redirect back to the first URL is a loop however
        // that URL was spelled. station() let the URL through, so it is an http or https URL with
        // a host, which target() always resolves; the URL itself only stands in for the type.
        $visited = [($this->target($url, $url) ?? $url) => true];
        $current = $url;

        while (in_array($response->status, self::REDIRECTS, true) && trim($response->header('Location') ?? '') !== '') {
            $target = $this->target($current, $response->header('Location'));

            if ($target === null) {
                return new FetchedChain($hops, $response, RedirectStop::InvalidTarget, $response->header('Location'));
            }

            $stop = match (true) {
                ! $allowDowngrade && $this->schemeOf($current) === 'https' && $this->schemeOf($target) === 'http' => RedirectStop::Downgrade,
                ! $scope->allows($first, strtolower((string) parse_url($target, PHP_URL_HOST))) => RedirectStop::OutOfScope,
                isset($visited[$target]) => RedirectStop::Loop,
                count($hops) - 1 >= $maxRedirects => RedirectStop::TooManyRedirects,
                default => null,
            };

            if ($stop instanceof RedirectStop) {
                return new FetchedChain($hops, $response, $stop, $target);
            }

            try {
                $next = $this->station($target, $maxBytes, $headers, $connectTimeout, $timeout);
            } catch (BlockedDestination) {
                return new FetchedChain($hops, $response, RedirectStop::Refused, $target);
            } catch (HostUnresolvable) {
                return new FetchedChain($hops, $response, RedirectStop::Unresolvable, $target);
            }

            $hops[] = new RedirectHop($target, $next->status, $next->header('Location'), $next->durationMs);
            $visited[$target] = true;
            $response = $next;
            $current = $target;
        }

        return new FetchedChain($hops, $response);
    }

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
     * @throws ResponseTimedOut when the server accepted the connection and its answer took longer than `$timeout`
     * @throws ConnectionException when the connection fails, or a timeout strikes while it is made
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

            throw ResponseTimedOut::refine($exception);
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
     * @throws ResponseTimedOut when the server accepted the connection and its headers took longer than `$timeout`
     * @throws ConnectionException when the connection fails, or a timeout strikes while it is made
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
            $psr = $head->response() ?? throw ResponseTimedOut::refine($exception);
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
     * One station of a chain: a lead without a body limit, a fetch under one.
     *
     * @param  array<string, string>  $headers
     */
    private function station(string $url, ?int $maxBytes, array $headers, int $connectTimeout, int $timeout): FetchedResponse
    {
        return $maxBytes === null
            ? $this->lead($url, $headers, $connectTimeout, $timeout)
            : $this->get($url, $maxBytes, $headers, $connectTimeout, $timeout);
    }

    /**
     * Where a `Location` leads from the URL that named it, as RFC 3986 resolves a reference, without
     * its fragment; null where that is no `http` or `https` URL with a host.
     */
    private function target(string $base, string $location): ?string
    {
        try {
            $resolved = UriResolver::resolve(new Uri($base), new Uri(trim($location)))->withFragment('');
        } catch (InvalidArgumentException) {
            return null;
        }

        return in_array(strtolower($resolved->getScheme()), ['http', 'https'], true) && $resolved->getHost() !== ''
            ? (string) $resolved
            : null;
    }

    private function schemeOf(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME));
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
