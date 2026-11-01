<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pushery\Webhooks\Core\Signing\Exceptions\InvalidMessage;

/**
 * A Guzzle middleware that signs each call it sends over its method, its target and its body, the content
 * {@see SignedRequest} describes, for Laravel's HTTP client:
 *
 *     Http::withoutRedirecting()
 *         ->withMiddleware(new SignsRequests($scheme, $secrets))
 *         ->post('https://partner.example/holds/7/release', ['reason' => 'expired']);
 *
 * It signs the request as it is about to leave, after the client has built the body and resolved the address, so the
 * signature covers the bytes that are sent and the target they are sent to. Each call is signed with a fresh id and
 * timestamp from {@see WebhookMessage::create()}, and so is each retry.
 *
 * A call that would follow a redirect is refused before anything is sent. A followed redirect is a second request, to
 * an address the receiver chose, and the client would sign it as well. With a key only the sender holds, as an
 * {@see Ed25519Scheme} has, that would hand the receiver a signature for any call it names.
 */
final readonly class SignsRequests
{
    public function __construct(
        private SignatureScheme $scheme,
        private SecretSet $secrets,
    ) {}

    /**
     * @param  callable(RequestInterface, array<array-key, mixed>): PromiseInterface  $handler
     * @return callable(RequestInterface, array<array-key, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return
            /**
             * @param  array<array-key, mixed>  $options
             */
            fn (RequestInterface $request, array $options): PromiseInterface => $handler($this->signed($request, $options), $options);
    }

    /**
     * The request with the signature headers of the scheme, and with the body it was signed over.
     *
     * @param  array<array-key, mixed>  $options
     *
     * @throws InvalidMessage when the call would follow a redirect, or its method or target cannot be signed
     */
    private function signed(RequestInterface $request, array $options): RequestInterface
    {
        if ($this->followsRedirects($options['allow_redirects'] ?? false)) {
            throw InvalidMessage::followsRedirects();
        }

        // Read once and sent as read: a body that cannot seek is consumed by reading it, and a signature over bytes that
        // never leave proves nothing.
        $body = (string) $request->getBody();
        $signed = $request->withBody(Utils::streamFor($body));

        $headers = $this->scheme->sign(
            WebhookMessage::create(SignedRequest::content($request->getMethod(), $request->getRequestTarget(), $body)),
            $this->secrets,
        );

        foreach ($headers->toArray() as $name => $value) {
            $signed = $signed->withHeader($name, $value);
        }

        return $signed;
    }

    /**
     * Whether Guzzle would follow a redirect under this `allow_redirects` setting: `true`, or an array whose `max` is
     * above zero. An array without `max` takes Guzzle's default of five.
     */
    private function followsRedirects(mixed $setting): bool
    {
        return is_array($setting) ? (bool) ($setting['max'] ?? true) : $setting === true;
    }
}
