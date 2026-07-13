<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * Inbound verification of GitHub webhook deliveries. GitHub signs the raw body with
 * HMAC-SHA256 over the webhook secret's raw bytes and sends the hex digest in an
 * `X-Hub-Signature-256` header, prefixed with the literal `sha256=`. There is no
 * timestamp in this dialect, so the tolerance argument is not consulted and a
 * verification never returns `expired`.
 *
 * This is a RECEIVE adapter only, selected per source via a Client config
 * `scheme`. It is never a sending default; {@see StandardWebhooksScheme} is.
 */
final readonly class GitHubScheme implements SignatureScheme
{
    public const string HEADER = 'X-Hub-Signature-256';

    private const string PREFIX = 'sha256=';

    public function __construct(
        private string $signatureHeader = self::HEADER,
    ) {}

    public function sign(WebhookMessage $message, SecretSet $secrets): SignatureHeaders
    {
        return SignatureHeaders::from([
            $this->signatureHeader => self::PREFIX.$this->hmac($message->rawBody, $secrets->current()),
        ]);
    }

    public function verify(string $rawBody, SignatureHeaders $headers, SecretSet $secrets, int $toleranceSeconds): VerificationResult
    {
        $header = $headers->get($this->signatureHeader);

        if ($header === null || ! str_starts_with($header, self::PREFIX)) {
            return VerificationResult::malformed();
        }

        $presented = substr($header, strlen(self::PREFIX));

        if ($presented === '') {
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
