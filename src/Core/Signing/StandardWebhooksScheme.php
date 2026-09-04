<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

use Illuminate\Support\Facades\Date;
use Pushery\Webhooks\Core\Signing\Exceptions\UnusableSigningSecret;

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
        $toSign = $this->signedContent($message->id, (string) $message->timestamp, $message->rawBody);

        $signatures = array_map(
            function (string $secret) use ($toSign): string {
                $key = self::key($secret);

                if ($key === null) {
                    // The send side is trusted and may fail loudly. Signing anyway would put a
                    // signature on the wire that anyone who sees the request can reproduce,
                    // while the headers claim the delivery is signed.
                    throw UnusableSigningSecret::derivesNoKey(self::SECRET_PREFIX);
                }

                return self::VERSION.','.$this->hmac($toSign, $key);
            },
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

        // The header as RECEIVED, not the integer it was normalized to for the window check
        // above. {@see self::signedContent()} for what that changes and why.
        $toSign = $this->signedContent($id, $timestamp, $rawBody);

        foreach ($secrets->all() as $keyId => $secret) {
            $key = self::key($secret);

            if ($key === null) {
                // Skipped rather than raised, on the same rule Ed25519Scheme::publicKey()
                // states: a secret that cannot verify anything must not turn an untrusted
                // request into a 500. If every secret is unusable the loop falls through to
                // invalid(), which is the honest answer.
                continue;
            }

            $expected = $this->hmac($toSign, $key);

            foreach ($presented as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return VerificationResult::valid($keyId);
                }
            }
        }

        return VerificationResult::invalid();
    }

    /**
     * The bytes the HMAC is taken over, per the spec: `{id}.{timestamp}.{body}`.
     *
     * The timestamp is a STRING here rather than an int, and that is the whole of a small
     * correction. Verification used to normalize the header to an integer first and sign that,
     * which had two consequences, both measured:
     *
     *   a producer that sends `0<ts>` and signs `{id}.0<ts>.{body}`, exactly as the spec says,
     *   was REFUSED, because this side computed `{id}.<ts>.{body}` instead
     *
     *   a delivery signed canonically stayed valid after somebody rewrote its header to
     *   `0<ts>`, because both spellings normalized to the same integer
     *
     * Neither buys an attacker anything -- the id and the body are still covered, and the
     * tolerance reads the same instant either way -- but the second one is exactly the property
     * the header was assumed to have and did not. Signing what was actually sent gives it.
     *
     * Nothing changes for a delivery this package signed: the signer has always written a plain
     * decimal, and an int and its string are the same bytes here.
     */
    private function signedContent(string $id, string $timestamp, string $rawBody): string
    {
        return $id.'.'.$timestamp.'.'.$rawBody;
    }

    /**
     * @param  non-empty-string  $key  the DERIVED key, never the configured secret
     */
    private function hmac(string $toSign, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $toSign, $key, true));
    }

    /**
     * Whether a configured secret derives an HMAC key at all.
     *
     * Public because the configuration checks need the SAME derivation the signing path
     * uses. Re-deriving it in a second place is how the two come apart, and this one is
     * not a place they may: a check that disagrees with the signer would pass exactly the
     * secrets the signer cannot use.
     */
    public static function derivesUsableKey(string $secret): bool
    {
        return self::key($secret) !== null;
    }

    /**
     * The raw HMAC key bytes, or null when the secret decodes to nothing.
     *
     * The derivation matches the reference SDKs exactly — strip an optional `whsec_`,
     * then base64-decode. What the reference does NOT do is notice the empty result, and
     * this package has a place to notice it, so it does. See {@see UnusableSigningSecret}
     * for why an empty key is not a key.
     *
     * @return non-empty-string|null
     */
    private static function key(string $secret): ?string
    {
        if (str_starts_with($secret, self::SECRET_PREFIX)) {
            $secret = substr($secret, strlen(self::SECRET_PREFIX));
        }

        $key = base64_decode($secret, false);

        return $key === '' ? null : $key;
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
