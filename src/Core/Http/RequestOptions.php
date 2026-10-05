<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use InvalidArgumentException;

/**
 * The request options a host may add to every delivery: any key Guzzle does not read itself.
 *
 * A host's middleware reads its own keys off a request, a purpose an egress policy asks every
 * outbound call for, say, and Laravel's HTTP client hands such a key on untouched. A key Guzzle
 * reads is a different matter: the transport owns redirects, TLS verification, the response
 * sink, the timeouts, the proxy and the IP pin, and a host option that set one of them would
 * loosen exactly what keeps a tenant-supplied endpoint in its place. So every Guzzle request
 * option is refused here, not only the ones the transport happens to set today.
 *
 * @internal
 */
final class RequestOptions
{
    /**
     * Guzzle's request options, the handler and client keys a request can carry, and the key
     * Laravel's HTTP client keeps its own data under.
     */
    public const array RESERVED = [
        'allow_redirects', 'auth', 'base_uri', 'body', 'cert', 'connect_timeout', 'cookies',
        'crypto_method', 'curl', 'debug', 'decode_content', 'delay', 'exceptions', 'expect',
        'force_ip_resolve', 'form_params', 'handler', 'headers', 'http_errors', 'idn_conversion',
        'json', 'laravel_data', 'multipart', 'on_headers', 'on_stats', 'progress', 'proxy', 'query',
        'read_timeout', 'save_to', 'sink', 'ssl_key', 'stream', 'stream_context', 'synchronous',
        'timeout', 'verify', 'version',
    ];

    /**
     * The options as given, once every key is one a host may add.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when a key is not an option name, or one the transport owns
     */
    public static function addable(array $options): array
    {
        $addable = [];

        foreach ($options as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('webhooks.server.request_options must map option names to values.');
            }

            if (in_array(strtolower($key), self::RESERVED, true)) {
                throw new InvalidArgumentException(sprintf(
                    'webhooks.server.request_options may not set [%s]: the transport owns that request option.',
                    $key,
                ));
            }

            $addable[$key] = $value;
        }

        return $addable;
    }
}
