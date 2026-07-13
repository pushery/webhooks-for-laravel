<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * The asymmetric (Ed25519) variant of the Standard Webhooks dialect. The signed
 * content is identical to {@see StandardWebhooksScheme} — `{id}.{timestamp}.{rawBody}`
 * — and it reuses the same `webhook-id` / `webhook-timestamp` header names and the
 * same timestamp-tolerance replay window. Only the signature differs: instead of a
 * shared-secret HMAC it carries an Ed25519 detached signature, tagged `v1a` so a
 * single `webhook-signature` header can present both a symmetric `v1` and an
 * asymmetric `v1a` entry side by side. A verifier ignores every non-`v1a` entry,
 * exactly as {@see StandardWebhooksScheme} ignores every non-`v1` one.
 *
 * Why asymmetric: the receiver only ever holds the PUBLIC key, so a leak of the
 * receiver's stored key material cannot be used to forge deliveries — the property
 * a shared HMAC secret can never offer. Provider public keys can also be published
 * (static, or via a JWKS endpoint) and rotated without redistributing a secret.
 *
 * Key encoding (both directions): base64 of the raw libsodium key bytes, with an
 * optional human-readable prefix that is stripped before decoding.
 *
 *  - sign():   the SecretSet's token is the 64-byte Ed25519 secret key (libsodium's
 *              expanded seed||public form from {@see \sodium_crypto_sign_keypair()}),
 *              base64-encoded, optionally prefixed `whsk_`.
 *  - verify(): each SecretSet token is a 32-byte Ed25519 public key, base64-encoded,
 *              optionally prefixed `whpk_`. During rotation the set holds the current
 *              plus the previous public key and a delivery verifies if EITHER matches.
 *
 * {@see \sodium_crypto_sign_verify_detached()} is constant-time by construction, so
 * no explicit `hash_equals` is needed here.
 */
final readonly class Ed25519Scheme implements SignatureScheme
{
    public const string HEADER_ID = StandardWebhooksScheme::HEADER_ID;

    public const string HEADER_TIMESTAMP = StandardWebhooksScheme::HEADER_TIMESTAMP;

    public const string HEADER_SIGNATURE = StandardWebhooksScheme::HEADER_SIGNATURE;

    public const string PUBLIC_PREFIX = 'whpk_';

    public const string SECRET_PREFIX = 'whsk_';

    private const string VERSION = 'v1a';

    public function __construct(
        private string $idHeader = self::HEADER_ID,
        private string $timestampHeader = self::HEADER_TIMESTAMP,
        private string $signatureHeader = self::HEADER_SIGNATURE,
    ) {}

    public function sign(WebhookMessage $message, SecretSet $secrets): SignatureHeaders
    {
        $toSign = $this->signedContent($message->id, $message->timestamp, $message->rawBody);

        $signatures = array_map(
            fn (string $secret): string => self::VERSION.','.base64_encode(sodium_crypto_sign_detached($toSign, $this->secretKey($secret))),
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
            $publicKey = $this->publicKey($secret);

            if ($publicKey === null) {
                continue;
            }

            foreach ($presented as $candidate) {
                if (strlen($candidate) === SODIUM_CRYPTO_SIGN_BYTES
                    && sodium_crypto_sign_verify_detached($candidate, $toSign, $publicKey)) {
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

    /**
     * The 64-byte Ed25519 secret key for signing. A wrong-length key here is an
     * operator misconfiguration on the trusted sending side, so it fails loudly.
     *
     * @return non-empty-string
     */
    private function secretKey(string $token): string
    {
        $key = $this->decode($token, self::SECRET_PREFIX);

        if (strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 signing key must be a base64-encoded 64-byte secret key.');
        }

        return $key;
    }

    /**
     * The 32-byte Ed25519 public key for verifying, or null when the configured key
     * is not a valid 32-byte key — a misconfigured public key simply cannot match an
     * untrusted request, so it is skipped rather than raised (a bad signature must
     * never surface as a 500).
     *
     * @return non-empty-string|null
     */
    private function publicKey(string $token): ?string
    {
        $key = $this->decode($token, self::PUBLIC_PREFIX);

        return strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    private function decode(string $token, string $prefix): string
    {
        if (str_starts_with($token, $prefix)) {
            $token = substr($token, strlen($prefix));
        }

        return base64_decode($token, false);
    }

    /**
     * The raw signature bytes carried by the `v1a,` entries of the header, ignoring
     * every other version (e.g. a symmetric `v1,`), space-separated per the spec.
     * The base64 payload is decoded to raw bytes here; a length mismatch is left in
     * the list to be rejected as non-matching by the constant-time verify.
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
                $signatures[] = base64_decode($signature, false);
            }
        }

        return $signatures;
    }
}
