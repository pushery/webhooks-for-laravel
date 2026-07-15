<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

use Illuminate\Support\Facades\Date;

/**
 * A Stripe-style HMAC scheme: signed content `{timestamp}.{rawBody}`, hex
 * HMAC-SHA256, carried in a single header `Webhook-Signature: t=<unix>,v1=<hex>`
 * (with one `v1=` per active secret during rotation). The key is the raw secret
 * bytes — NOT base64-decoded — which is what Stripe-style producers use.
 *
 * It is available as an inbound verification adapter, selected per source on the Client
 * layer to RECEIVE Stripe-style producers; it is never a sending default
 * ({@see StandardWebhooksScheme} is). It is also the generic base for
 * {@see StripeScheme}, which pins the real `Stripe-Signature` header.
 */
readonly class StripeStyleScheme implements AcceptsSignatureHeaders, SignatureScheme
{
    public const string HEADER = 'Webhook-Signature';

    public function __construct(
        private string $signatureHeader = self::HEADER,
    ) {}

    public function withSignatureHeaders(?string $idHeader, ?string $timestampHeader, ?string $signatureHeader): SignatureScheme
    {
        return new self($signatureHeader ?? $this->signatureHeader);
    }

    public function sign(WebhookMessage $message, SecretSet $secrets): SignatureHeaders
    {
        $toSign = $message->timestamp.'.'.$message->rawBody;

        $signatures = array_map(
            fn (string $secret): string => 'v1='.$this->hmac($toSign, $secret),
            array_values($secrets->all()),
        );

        return SignatureHeaders::from([
            $this->signatureHeader => 't='.$message->timestamp.','.implode(',', $signatures),
        ]);
    }

    public function verify(string $rawBody, SignatureHeaders $headers, SecretSet $secrets, int $toleranceSeconds): VerificationResult
    {
        $header = $headers->get($this->signatureHeader);

        if ($header === null) {
            return VerificationResult::malformed();
        }

        ['timestamp' => $timestamp, 'signatures' => $presented] = $this->parse($header);

        if ($timestamp === null || $presented === []) {
            return VerificationResult::malformed();
        }

        if (abs(Date::now()->getTimestamp() - $timestamp) > $toleranceSeconds) {
            return VerificationResult::expired();
        }

        $toSign = $timestamp.'.'.$rawBody;

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

    private function hmac(string $toSign, string $secret): string
    {
        return hash_hmac('sha256', $toSign, $secret);
    }

    /**
     * @return array{timestamp: int|null, signatures: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && preg_match('/^\d+$/', $value) === 1) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}
