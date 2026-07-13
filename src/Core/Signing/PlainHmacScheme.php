<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * A plain HMAC scheme for inbound verification of producers that sign the raw body
 * alone — a common raw-body HMAC dialect verified by many Laravel webhook-client
 * packages by default. Signed content is the exact raw body; the signature is a hex-encoded
 * HMAC-SHA256 carried in a single `Signature` header (overridable); the HMAC key is
 * the raw secret bytes, used as-is with no prefix stripping or base64 decoding.
 *
 * There is no timestamp in this dialect, so there is nothing to expire — the
 * tolerance argument is not consulted and a verification never returns `expired`.
 *
 * This is a RECEIVE adapter only, selected per source via a Client config
 * `scheme`. It is never a sending default; {@see StandardWebhooksScheme} is.
 */
final readonly class PlainHmacScheme implements SignatureScheme
{
    public const string HEADER = 'Signature';

    public function __construct(
        private string $signatureHeader = self::HEADER,
    ) {}

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
        return hash_hmac('sha256', $rawBody, $secret);
    }
}
