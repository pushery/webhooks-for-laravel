<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

/**
 * A shared secret the producer sends as it is, in a header of its own, the way GitLab sends the
 * secret token of a webhook in `X-Gitlab-Token` when no signing token is set.
 *
 * Nothing is signed. The header is compared with the current and the previous secret, in constant
 * time and without revealing the secret's length, and a match says only that the sender knows the
 * secret. It says nothing about the body, and the secret travels with every delivery: whoever reads
 * one delivery, from a log, a proxy or a misdirected request, can send any body as the producer.
 * That is weaker than every other scheme here, which keep the secret on both ends and send a
 * signature made with it. Where a producer offers a signature, verify that instead: GitLab signs in
 * the Standard Webhooks format when a signing token is set, which {@see StandardWebhooksScheme}
 * reads.
 *
 * There is no timestamp in this dialect, so there is nothing to expire: the tolerance argument is
 * not consulted and a verification never returns `expired`. There is no delivery id either, so a
 * source names its idempotency key with `dedupe_id`.
 *
 * This is a receive adapter only, selected per source via a Client config `scheme`, with the header
 * name from `signature_headers.signature`. It is never a sending default.
 */
final readonly class SecretTokenScheme implements AcceptsSignatureHeaders, SignatureScheme
{
    /** GitLab's header, the producer this dialect is most often met with. */
    public const string HEADER = 'X-Gitlab-Token';

    public function __construct(private string $tokenHeader = self::HEADER) {}

    public function withSignatureHeaders(?string $idHeader, ?string $timestampHeader, ?string $signatureHeader): SignatureScheme
    {
        return new self($signatureHeader ?? $this->tokenHeader);
    }

    /**
     * Sends the current secret alone: the header has room for one, so during a rotation the
     * previous secret only verifies.
     */
    public function sign(WebhookMessage $message, SecretSet $secrets): SignatureHeaders
    {
        return SignatureHeaders::from([$this->tokenHeader => $secrets->current()]);
    }

    public function verify(string $rawBody, SignatureHeaders $headers, SecretSet $secrets, int $toleranceSeconds): VerificationResult
    {
        $presented = $headers->get($this->tokenHeader);

        if ($presented === null || $presented === '') {
            return VerificationResult::malformed();
        }

        // Compared as digests of one length, so the time the comparison takes says nothing about how
        // long the secret is, as a comparison of the strings themselves would.
        $digest = hash('sha256', $presented, true);

        foreach ($secrets->all() as $keyId => $secret) {
            if (hash_equals(hash('sha256', $secret, true), $digest)) {
                return VerificationResult::valid($keyId);
            }
        }

        return VerificationResult::invalid();
    }
}
