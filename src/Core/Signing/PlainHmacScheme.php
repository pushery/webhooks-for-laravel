<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

/**
 * A plain HMAC scheme for inbound verification of producers that sign the raw body
 * alone — a common raw-body HMAC dialect verified by many Laravel webhook-client
 * packages by default. Signed content is the exact raw body; the signature is an HMAC-SHA256
 * carried in a single `Signature` header (overridable), written as hex unless the source asks for
 * Base64 ({@see self::withSignatureEncoding()}); the HMAC key is the raw secret bytes, used as-is
 * with no prefix stripping or base64 decoding.
 *
 * There is no timestamp in this dialect, so there is nothing to expire — the
 * tolerance argument is not consulted and a verification never returns `expired`.
 *
 * This is a receive adapter only, selected per source via a Client config
 * `scheme`. It is never a sending default; {@see StandardWebhooksScheme} is.
 */
final readonly class PlainHmacScheme implements AcceptsSignatureHeaders, SignatureScheme
{
    public const string HEADER = 'Signature';

    public function __construct(
        private string $signatureHeader = self::HEADER,
        private SignatureEncoding $encoding = SignatureEncoding::Hex,
    ) {}

    public function withSignatureHeaders(?string $idHeader, ?string $timestampHeader, ?string $signatureHeader): SignatureScheme
    {
        return new self($signatureHeader ?? $this->signatureHeader, $this->encoding);
    }

    /**
     * The same scheme with its digest written in the given encoding: Base64 for a producer such as
     * Shopify or WooCommerce, which sends the HMAC that way.
     */
    public function withSignatureEncoding(SignatureEncoding $encoding): self
    {
        return new self($this->signatureHeader, $encoding);
    }

    /**
     * Signs with the current secret alone: the header has room for one signature, so during a
     * rotation the previous secret only verifies.
     */
    public function sign(WebhookMessage $message, SecretSet $secrets): SignatureHeaders
    {
        return SignatureHeaders::from([
            $this->signatureHeader => $this->hmac($message->rawBody, $secrets->current()),
        ]);
    }

    public function verify(string $rawBody, SignatureHeaders $headers, SecretSet $secrets, int $toleranceSeconds): VerificationResult
    {
        $presented = $headers->get($this->signatureHeader);

        if ($presented === null || $presented === '') {
            return VerificationResult::malformed();
        }

        foreach ($secrets->all() as $keyId => $secret) {
            if (hash_equals($this->hmac($rawBody, $secret), $presented)) {
                return VerificationResult::valid($keyId);
            }
        }

        return VerificationResult::invalid();
    }

    private function hmac(string $rawBody, string $secret): string
    {
        return $this->encoding->encode(hash_hmac('sha256', $rawBody, $secret, true));
    }
}
