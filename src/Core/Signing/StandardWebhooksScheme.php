<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

use Illuminate\Support\Facades\Date;

/**
 * The default signature dialect: byte-compatible with the industry Standard
 * Webhooks specification and the official `standardwebhooks/standardwebhooks`
 * SDKs (JS/Python/Go/Ruby/Rust/Java/C#/Elixir). Any Standard Webhooks consumer
 * can verify our deliveries out of the box, and we can verify theirs.
 *
 * Signed content: `{id}.{timestamp}.{rawBody}`, HMAC-SHA256, base64-encoded.
 * Headers: `webhook-id`, `webhook-timestamp`, and a space-separated
 * `webhook-signature: v1,<b64> v1,<b64>` — one `v1,` per active secret so a
 * rotation emits two and a receiver accepts if either verifies.
 *
 * Key derivation matches the reference exactly: strip an optional `whsec_`
 * prefix, then base64-decode the remainder to the raw HMAC key bytes.
 */
final readonly class StandardWebhooksScheme implements SignatureScheme
{
    public const string HEADER_ID = 'webhook-id';

    public const string HEADER_TIMESTAMP = 'webhook-timestamp';

    public const string HEADER_SIGNATURE = 'webhook-signature';

    private const string SECRET_PREFIX = 'whsec_';

    private const string VERSION = 'v1';

    public function __construct(
        private string $idHeader = self::HEADER_ID,
        private string $timestampHeader = self::HEADER_TIMESTAMP,
        private string $signatureHeader = self::HEADER_SIGNATURE,
    ) {}

    public function sign(WebhookMessage $message, SecretSet $secrets): SignatureHeaders
    {
        $toSign = $this->signedContent($message->id, $message->timestamp, $message->rawBody);

        $signatures = array_map(
            fn (string $secret): string => self::VERSION.','.$this->hmac($toSign, $secret),
            array_values($secrets->all()),
        );

        return SignatureHeaders::from([
            $this->idHeader => $message->id,
            $this->timestampHeader => (string) $message->timestamp,
            $this->signatureHeader => implode(' ', $signatures),
        ]);
    }

    public function verify(string $rawBody, SignatureHeaders $headers, SecretSet $secrets, int $toleranceSeconds): VerificationResult
    {
        $id = $headers->get($this->idHeader);
        $timestamp = $headers->get($this->timestampHeader);
        $signatureHeader = $headers->get($this->signatureHeader);

        if ($id === null || $id === '' || $timestamp === null || $signatureHeader === null
            || preg_match('/^\d+$/', $timestamp) !== 1) {
            return VerificationResult::malformed();
        }

        $timestampValue = (int) $timestamp;

        if (abs(Date::now()->getTimestamp() - $timestampValue) > $toleranceSeconds) {
            return VerificationResult::expired();
        }

        $presented = $this->presentedSignatures($signatureHeader);

        if ($presented === []) {
            return VerificationResult::malformed();
        }

        $toSign = $this->signedContent($id, $timestampValue, $rawBody);

        foreach ($secrets->all() as $keyId => $secret) {
            $expected = $this->hmac($toSign, $secret);

            foreach ($presented as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return VerificationResult::valid($keyId);
                }
            }
        }

        return VerificationResult::invalid();
    }

    private function signedContent(string $id, int $timestamp, string $rawBody): string
    {
        return $id.'.'.$timestamp.'.'.$rawBody;
    }

    private function hmac(string $toSign, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $toSign, $this->key($secret), true));
    }

    private function key(string $secret): string
    {
        if (str_starts_with($secret, self::SECRET_PREFIX)) {
            $secret = substr($secret, strlen(self::SECRET_PREFIX));
        }

        return base64_decode($secret, false);
    }

    /**
     * The base64 signatures carried by the `v1,` entries of the header, ignoring
     * any other version (e.g. `v1a,` Ed25519), space-separated per the spec.
     *
     * @return list<string>
     */
    private function presentedSignatures(string $header): array
    {
        $signatures = [];

        foreach (explode(' ', $header) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');

            if ($version === self::VERSION && $signature !== '') {
                $signatures[] = $signature;
            }
        }

        return $signatures;
    }
}
