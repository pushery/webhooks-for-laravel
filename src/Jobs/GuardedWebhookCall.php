<?php

declare(strict_types=1);

namespace Webhooks\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Override;
use Spatie\WebhookServer\CallWebhookJob;
use Webhooks\Contracts\EndpointGuard;
use Webhooks\Exceptions\BlockedEndpointException;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Signing\WebhookSigner;

/**
 * The spatie delivery job, hardened for untrusted endpoints. It is installed as
 * the global webhook job but only hardens deliveries this package dispatched
 * (identified by the delivery id in the call's meta), so the host application's
 * own spatie webhook calls behave exactly as before.
 *
 * For a package delivery it: (1) never follows redirects; (2) validates the URL
 * and PINS the connection to the exact validated IP addresses via CURLOPT_RESOLVE,
 * so curl cannot re-resolve to a different (internal) address — the only real
 * defence against DNS rebinding, and it also stops an IPv6 record from being used
 * to reach an address the guard never checked; and (3) re-signs the payload at send
 * time so a queued/retried delivery's signature timestamp is never consumed by
 * queue dwell. IPv6-only hosts fail closed (they do not resolve to an A record and
 * are refused).
 */
final class GuardedWebhookCall extends CallWebhookJob
{
    #[Override]
    protected function getClient(): ClientInterface
    {
        $options = ['handler' => app(HandlerStack::class)];

        if ($this->isPackageDelivery()) {
            $options['allow_redirects'] = false;
            $options['curl'] = $this->pinnedConnection();
        }

        return new Client($options);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    #[Override]
    protected function createRequest(array $body): Response
    {
        if ($this->isPackageDelivery()) {
            $this->signAtSendTime();
        }

        return parent::createRequest($body);
    }

    /**
     * Validate the endpoint and pin curl to the exact validated IP(s). A blocked
     * endpoint is surfaced through spatie's normal failure path as a connection error.
     *
     * @return array<int, list<string>>
     */
    private function pinnedConnection(): array
    {
        $url = (string) $this->webhookUrl;

        try {
            $ips = app(EndpointGuard::class)->validate($url);
        } catch (BlockedEndpointException $exception) {
            throw new ConnectException($exception->getMessage(), new Request($this->httpVerb, $url), $exception);
        }

        if ($ips === []) {
            return [];
        }

        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443);

        return [CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $ips)]];
    }

    private function signAtSendTime(): void
    {
        $subscriptionId = $this->meta['subscription_id'] ?? null;
        $subscription = is_int($subscriptionId) ? WebhookSubscription::query()->find($subscriptionId) : null;

        if ($subscription instanceof WebhookSubscription && is_array($this->payload)) {
            $signer = app(WebhookSigner::class);

            $this->headers[$signer->signatureHeaderName()] = $signer->calculateSignature(
                (string) $this->webhookUrl,
                $this->payload,
                $subscription->signingSecrets(),
            );
        }
    }

    private function isPackageDelivery(): bool
    {
        return isset($this->meta['delivery_id']);
    }
}
