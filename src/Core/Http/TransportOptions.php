<?php

declare(strict_types=1);

namespace Webhooks\Core\Http;

/**
 * Transport-level options for a single delivery attempt: the HTTP verb, the
 * connect/total timeouts, TLS verification, an optional egress proxy, optional
 * mutual-TLS client credentials, the body content type, and the response-capture
 * byte cap. Deliberately signing- and payload-agnostic — the {@see HttpTransport}
 * receives the raw body and headers separately.
 *
 * @internal
 */
final readonly class TransportOptions
{
    public function __construct(
        public string $verb = 'post',
        public int $connectTimeout = 3,
        public int $timeout = 5,
        public bool|string $verify = true,
        public ?string $proxy = null,
        public ?string $clientCert = null,
        public ?string $clientKey = null,
        public ?string $clientCertPassphrase = null,
        public string $contentType = 'application/json',
        public int $responseCaptureBytes = 65536,
    ) {}
}
