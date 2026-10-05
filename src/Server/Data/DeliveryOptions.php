<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Data;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Pushery\Webhooks\Core\Http\TransportOptions;
use Pushery\Webhooks\Support\Settings;

/**
 * The transport + delivery options collected by the `Pushery\Webhooks\Server\PendingWebhook`
 * builder for one call, queue-serializable as part of {@see WebhookDeliveryData}.
 * A superset of {@see TransportOptions} that also carries the Retry-After policy: whether
 * a retryable 429/503 hint is obeyed at all, the longest wait the queue can hold
 * (retryAfterCap), and how many times a delivery may wait that cap out without
 * charging its retry budget (retryAfterMaxDeferrals). None of them is a transport
 * concern, so {@see self::toTransportOptions()} drops them all.
 *
 * $largePayloadThreshold is read by nothing and always 0. The outbound large-payload offload
 * is configured under `webhooks.server.large_payload` and applies to the Platform delivery
 * log only. The field stays because a job queued by an earlier version carries it, and a
 * readonly class cannot unserialize a property it no longer declares: the job would fail in
 * the worker.
 *
 * The $clientCertPassphrase carried here is SEALED with the app encrypter (like the signing
 * secret), so a mutual-TLS credential is never at rest in cleartext in the queue store or in
 * an attempt-event payload; {@see self::toTransportOptions()} unseals it at send time. Build
 * this through `PendingWebhook::useMutualTls()`, which seals it — never with a plaintext
 * passphrase, which toTransportOptions() would then fail to decrypt.
 *
 * The $proxy is sealed the same way, by `PendingWebhook::toDeliveryData()`, because a proxy
 * that authenticates carries its credentials in the URL. One that does not decrypt is read
 * as the plain URL a job queued before the proxy was sealed carries, so an upgrade does not
 * fail the deliveries already waiting in the queue.
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
            proxy: $this->unsealedProxy(),
            clientCert: $this->clientCert,
            clientKey: $this->clientKey,
            clientCertPassphrase: $this->clientCertPassphrase === null
                ? null
                : Crypt::decryptString($this->clientCertPassphrase),
            contentType: $this->contentType,
            responseCaptureBytes: $this->responseCaptureBytes,
            // Read here, on the worker, rather than carried through the queue: an option added to
            // this class would be missing from every job queued before the upgrade, and reading a
            // property such a job never had throws.
            requestOptions: new Settings()->requestOptions(),
        );
    }

    /**
     * The proxy URL for the transport: unsealed, or as it is when it was never sealed. Only a
     * job queued before the proxy was sealed carries it in the clear, and failing every one of
     * those at the first attempt after an upgrade would turn a deploy into lost deliveries.
     */
    private function unsealedProxy(): ?string
    {
        if ($this->proxy === null) {
            return null;
        }

        try {
            return Crypt::decryptString($this->proxy);
        } catch (DecryptException) {
            return $this->proxy;
        }
    }
}
