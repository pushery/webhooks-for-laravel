<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * A pluggable webhook signature dialect, shared verbatim between the sending
 * (Server) and receiving (Client) layers. The default is {@see StandardWebhooksScheme},
 * which is byte-compatible with the industry Standard Webhooks SDKs.
 *
 * A scheme signs the exact bytes it is handed and verifies against those same
 * bytes — it never re-encodes the payload, which is the single most common
 * webhook interoperability bug.
 */
interface SignatureScheme
{
    /**
     * Produce the signature header(s) for an outgoing message. Called on the
     * sending side, at send time, over the final serialized body.
     */
    public function sign(WebhookMessage $message, SecretSet $secrets): SignatureHeaders;

    /**
     * Verify an incoming request. Called on the receiving (Client) side over the
     * exact captured raw bytes. Returns a typed result rather than throwing, so
     * the caller decides the HTTP response (a bad signature is untrusted input,
     * not a programmer error).
     *
     * @param  int  $toleranceSeconds  reject when the signed timestamp is outside
     *                                 this window (replay protection)
     */
    public function verify(
        string $rawBody,
        SignatureHeaders $headers,
        SecretSet $secrets,
        int $toleranceSeconds,
    ): VerificationResult;
}
