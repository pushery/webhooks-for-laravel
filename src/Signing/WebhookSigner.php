<?php

declare(strict_types=1);

namespace Webhooks\Signing;

use Illuminate\Support\Facades\Config;
use Spatie\WebhookServer\Signer\Signer;

/**
 * Signs each webhook with a Stripe-style header:
 *
 *     Webhook-Signature: t=<unix>,v1=<hmac_sha256(t . "." . body)>
 *
 * The timestamp is part of the signed material so a consumer can reject replays.
 * During a secret rotation the caller passes every active secret (newline
 * separated); each is signed and emitted as its own `v1=` value, so consumers
 * verify successfully whether they still hold the old secret or the new one.
 *
 * The signature is computed over exactly the JSON body the HTTP client sends
 * (spatie serialises `json_encode($payload)` with default flags), so a consumer
 * verifying against the raw request body always matches.
 */
final class WebhookSigner implements Signer
{
    public function signatureHeaderName(): string
    {
        return Config::string('webhooks.signature.header_name', 'Webhook-Signature');
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function calculateSignature(string $webhookUrl, array $payload, string $secret): string
    {
        $timestamp = (string) now()->getTimestamp();
        $signedPayload = $timestamp.'.'.json_encode($payload, JSON_THROW_ON_ERROR);

        $signatures = array_map(
            static fn (string $activeSecret): string => 'v1='.hash_hmac('sha256', $signedPayload, $activeSecret),
            $this->activeSecrets($secret),
        );

        return 't='.$timestamp.','.implode(',', $signatures);
    }

    /**
     * @return list<string>
     */
    private function activeSecrets(string $secret): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode("\n", $secret)),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
