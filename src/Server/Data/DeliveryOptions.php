<?php

declare(strict_types=1);

namespace Webhooks\Server\Data;

use Illuminate\Support\Facades\Crypt;
use Webhooks\Core\Http\TransportOptions;

/**
 * The transport + delivery options collected by the `Webhooks\Server\PendingWebhook`
 * builder for one call, queue-serializable as part of {@see WebhookDeliveryData}.
 * A superset of {@see TransportOptions} that also carries the large-payload offload
 * threshold — a day-zero seam so a later offload feature is a pure activation, not
 * a signature change (default 0 = always inline) — and the Retry-After policy: whether
 * a retryable 429/503 hint is obeyed at all, the longest wait the queue can hold
 * (retryAfterCap), and how many times a delivery may wait that cap out without
 * charging its retry budget (retryAfterMaxDeferrals). None of them is a transport
 * concern, so {@see self::toTransportOptions()} drops them all.
 *
 * The $clientCertPassphrase carried here is SEALED with the app encrypter (like the signing
 * secret), so a mutual-TLS credential is never at rest in cleartext in the queue store or in
 * an attempt-event payload; {@see self::toTransportOptions()} unseals it at send time. Build
 * this through `PendingWebhook::useMutualTls()`, which seals it — never with a plaintext
 * passphrase, which toTransportOptions() would then fail to decrypt.
 */
final readonly class DeliveryOptions
{
    public function __construct(
        public string $verb = 'post',
        public int $connectTimeout = 3,
        public int $timeout = 5,
        public bool|string $verifySsl = true,
        public ?string $proxy = null,
        public ?string $clientCert = null,
        public ?string $clientKey = null,
        public ?string $clientCertPassphrase = null,
        public string $contentType = 'application/json',
        public int $responseCaptureBytes = 65536,
        public int $largePayloadThreshold = 0,
        public bool $respectRetryAfter = true,
        public int $retryAfterCap = 900,
        public int $retryAfterMaxDeferrals = 6,
    ) {}

    /**
     * Adapt to the Core transport options at send time. The client-cert passphrase is
     * carried SEALED through the queue (see the class docblock) and is unsealed HERE, at
     * the Server→Core handoff on the worker — the same place and moment the signing secret
     * is unsealed — so the plaintext exists only in the transient options handed to the
     * transport, never at rest in the queue store or in an attempt-event payload.
     */
    public function toTransportOptions(): TransportOptions
    {
        return new TransportOptions(
            verb: $this->verb,
            connectTimeout: $this->connectTimeout,
            timeout: $this->timeout,
            verify: $this->verifySsl,
            proxy: $this->proxy,
            clientCert: $this->clientCert,
            clientKey: $this->clientKey,
            clientCertPassphrase: $this->clientCertPassphrase === null
                ? null
                : Crypt::decryptString($this->clientCertPassphrase),
            contentType: $this->contentType,
            responseCaptureBytes: $this->responseCaptureBytes,
        );
    }
}
