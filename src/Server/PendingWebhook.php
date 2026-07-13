<?php

declare(strict_types=1);

namespace Webhooks\Server;

use Closure;
use Illuminate\Support\Str;
use JsonException;
use Webhooks\Core\Signing\JsonCanonicalizer;
use Webhooks\Core\Signing\SecretSet;
use Webhooks\Core\Signing\SignatureScheme;
use Webhooks\Core\Signing\StandardWebhooksScheme;
use Webhooks\Server\Backoff\BackoffStrategy;
use Webhooks\Server\Backoff\ExponentialWithJitter;
use Webhooks\Server\Data\DeliveryOptions;
use Webhooks\Server\Data\WebhookDeliveryData;
use Webhooks\Server\Events\WebhookDeliveryDispatching;
use Webhooks\Server\Jobs\CallWebhookJob;
use Webhooks\Server\Signing\EncryptedSecretResolver;
use Webhooks\Support\Settings;

/**
 * The immutable, fluent builder for sending a webhook — the shape of Laravel's own
 * PendingRequest/PendingMail, and named for it. Each setter returns a CLONE, so a
 * half-built call is a reusable template. Consumers never write a service/action class
 * to send; they call this builder and react to the Webhooks\Server\Events\* attempt
 * events via listeners.
 *
 * `Webhooks\Server\Facades\WebhookSender::to($url)` is the same builder behind a name
 * that reads as a verb, and is how most application code reaches it.
 *
 *     PendingWebhook::create()
 *         ->url('https://example.com/webhooks')
 *         ->payload(['invoice_id' => 'in_123'])
 *         ->useSecret('whsec_…')
 *         ->dispatch();
 */
final class PendingWebhook
{
    private string $url = '';

    /** @var array<array-key, mixed>|null */
    private ?array $payload = null;

    private ?string $rawBody = null;

    private bool $canonicalize = false;

    private string $contentType = 'application/json';

    private ?SecretSet $secrets = null;

    private bool $doNotSign = false;

    /** @var class-string<SignatureScheme> */
    private string $schemeClass = StandardWebhooksScheme::class;

    private ?string $messageId = null;

    private ?string $eventType = null;

    private string $verb = 'post';

    /** @var array<string, string> */
    private array $headers = [];

    private int $connectTimeout = 3;

    private int $timeout = 5;

    private bool|string $verifySsl = true;

    private ?string $proxy = null;

    private ?string $clientCert = null;

    private ?string $clientKey = null;

    private ?string $clientCertPassphrase = null;

    private int $responseCaptureBytes = 65536;

    private int $largePayloadThreshold = 0;

    private bool $respectRetryAfter = true;

    private int $retryAfterCap = 900;

    private int $retryAfterMaxDeferrals = 6;

    private int $tries = 3;

    private ?BackoffStrategy $backoff = null;

    /** @var array<string, scalar|null> */
    private array $meta = [];

    /** @var list<string> */
    private array $tags = [];

    private ?string $queue = null;

    private ?string $connection = null;

    private int $delaySeconds = 0;

    /**
     * A call seeded with the engine's configured defaults — the signing dialect, the
     * HTTP verb, the timeouts, the try count, TLS verification, canonicalization, the
     * Retry-After policy, the retry schedule and the egress proxy. Config is the
     * default for EVERY delivery; the builder below overrides it per call. Seeding
     * here (rather than only where the Platform layer fans out) is what makes a
     * host's webhooks.server settings hold for a directly-driven call too.
     */
    public static function create(): self
    {
        $config = new Settings;
        $call = new self;

        $call->schemeClass = $config->signingScheme();
        $call->verb = $config->httpVerb();
        $call->connectTimeout = $config->connectTimeout();
        $call->timeout = $config->timeout();
        $call->tries = $config->tries();
        $call->verifySsl = $config->verifySsl();
        $call->canonicalize = $config->canonicalizeJson();
        $call->respectRetryAfter = $config->respectRetryAfter();
        $call->retryAfterCap = $config->retryAfterCap();
        $call->retryAfterMaxDeferrals = $config->retryAfterMaxDeferrals();
        $call->responseCaptureBytes = $config->responseCaptureBytes();
        $call->backoff = $config->backoffStrategy();
        $call->proxy = $config->egressProxy();

        // Asymmetric mode signs with the Server's OWN Ed25519 key, so the key travels
        // with the call from here; no per-endpoint shared secret is involved.
        $ed25519Key = $config->ed25519SigningKey();

        if ($ed25519Key !== null) {
            $call->secrets = SecretSet::fromCurrent($ed25519Key);
        }

        return $call;
    }

    public function url(string $url): self
    {
        return tap(clone $this, fn (self $call): string => $call->url = $url);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function payload(array $payload): self
    {
        return tap(clone $this, function (self $call) use ($payload): void {
            $call->payload = $payload;
            $call->rawBody = null;
        });
    }

    public function sendRawBody(string $body, string $contentType = 'application/json'): self
    {
        return tap(clone $this, function (self $call) use ($body, $contentType): void {
            $call->rawBody = $body;
            $call->payload = null;
            $call->contentType = $contentType;
        });
    }

    /**
     * Canonicalize the JSON payload (sorted keys) BEFORE signing, so signed and
     * sent bytes stay identical for a receiver that re-canonicalizes. Opt-in.
     */
    public function canonicalizeJson(bool $canonicalize = true): self
    {
        return tap(clone $this, fn (self $call): bool => $call->canonicalize = $canonicalize);
    }

    public function useSecret(string $secret): self
    {
        return tap(clone $this, fn (self $call): SecretSet => $call->secrets = SecretSet::fromCurrent($secret));
    }

    public function useSecrets(string $current, string $previous): self
    {
        return tap(clone $this, fn (self $call): SecretSet => $call->secrets = SecretSet::rotating($current, $previous));
    }

    public function doNotSign(bool $doNotSign = true): self
    {
        return tap(clone $this, fn (self $call): bool => $call->doNotSign = $doNotSign);
    }

    /**
     * @param  class-string<SignatureScheme>  $scheme
     */
    public function signUsing(string $scheme): self
    {
        return tap(clone $this, fn (self $call): string => $call->schemeClass = $scheme);
    }

    /**
     * Pin a stable message id (the Standard Webhooks `webhook-id`). The Platform
     * layer passes its event id here so a redelivery re-signs with the same id and
     * the DB event_id equals the wire id.
     */
    public function useMessageId(string $messageId): self
    {
        return tap(clone $this, fn (self $call): string => $call->messageId = $messageId);
    }

    public function forEventType(?string $eventType): self
    {
        return tap(clone $this, fn (self $call): ?string => $call->eventType = $eventType);
    }

    public function useHttpVerb(string $verb): self
    {
        return tap(clone $this, fn (self $call) => $call->verb = strtolower($verb));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return tap(clone $this, fn (self $call): array => $call->headers = [...$call->headers, ...$headers]);
    }

    public function timeoutInSeconds(int $timeout): self
    {
        return tap(clone $this, fn (self $call): int => $call->timeout = $timeout);
    }

    public function connectTimeoutInSeconds(int $connectTimeout): self
    {
        return tap(clone $this, fn (self $call): int => $call->connectTimeout = $connectTimeout);
    }

    public function verifySsl(bool|string $verify = true): self
    {
        return tap(clone $this, fn (self $call): bool|string => $call->verifySsl = $verify);
    }

    public function useProxy(string $proxy): self
    {
        return tap(clone $this, fn (self $call): string => $call->proxy = $proxy);
    }

    public function useMutualTls(string $cert, ?string $key = null, ?string $passphrase = null): self
    {
        return tap(clone $this, function (self $call) use ($cert, $key, $passphrase): void {
            $call->clientCert = $cert;
            $call->clientKey = $key;
            $call->clientCertPassphrase = $passphrase;
        });
    }

    /**
     * Obey a retryable 429/503 Retry-After header when scheduling the next attempt
     * (clamped to the Retry-After cap) instead of the jittered delay. On by default.
     */
    public function respectRetryAfter(bool $respect = true): self
    {
        return tap(clone $this, fn (self $call): bool => $call->respectRetryAfter = $respect);
    }

    /**
     * The longest Retry-After wait this queue can hold a job for. A hint beyond it is
     * waited out at the cap WITHOUT charging the delivery's retry budget, up to
     * $maxDeferrals times, so a long rate-limit window cannot exhaust the delivery
     * before the endpoint is ready for it.
     */
    public function retryAfterCapInSeconds(int $seconds, ?int $maxDeferrals = null): self
    {
        return tap(clone $this, function (self $call) use ($seconds, $maxDeferrals): void {
            $call->retryAfterCap = max(0, $seconds);
            $call->retryAfterMaxDeferrals = max(0, $maxDeferrals ?? $call->retryAfterMaxDeferrals);
        });
    }

    /**
     * How many bytes of the endpoint's response are captured for the delivery log. The
     * rest is read off the wire and discarded, never buffered.
     */
    public function captureResponseBytes(int $bytes): self
    {
        return tap(clone $this, fn (self $call): int => $call->responseCaptureBytes = max(0, $bytes));
    }

    public function maximumTries(int $tries): self
    {
        return tap(clone $this, fn (self $call): mixed => $call->tries = max(1, $tries));
    }

    public function useBackoffStrategy(BackoffStrategy $backoff): self
    {
        return tap(clone $this, fn (self $call): BackoffStrategy => $call->backoff = $backoff);
    }

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function meta(array $meta): self
    {
        return tap(clone $this, fn (self $call): array => $call->meta = [...$call->meta, ...$meta]);
    }

    /**
     * @param  list<string>  $tags
     */
    public function withTags(array $tags): self
    {
        return tap(clone $this, fn (self $call): array => $call->tags = [...$call->tags, ...$tags]);
    }

    /**
     * Hold the delivery in the queue for this many seconds before its first attempt —
     * how the Platform layer shapes an over-limit endpoint's traffic instead of
     * dropping the event.
     */
    public function delayInSeconds(int $seconds): self
    {
        return tap(clone $this, fn (self $call): int => $call->delaySeconds = max(0, $seconds));
    }

    public function onQueue(?string $queue): self
    {
        return tap(clone $this, fn (self $call): ?string => $call->queue = $queue);
    }

    public function onConnection(?string $connection): self
    {
        return tap(clone $this, fn (self $call): ?string => $call->connection = $connection);
    }

    /**
     * @throws JsonException
     */
    public function dispatch(): void
    {
        $this->dispatchUsing(dispatch(...));
    }

    /**
     * @throws JsonException
     */
    public function dispatchSync(): void
    {
        $this->dispatchUsing(dispatch_sync(...));
    }

    /**
     * @throws JsonException
     */
    public function dispatchIf(bool $condition): void
    {
        if ($condition) {
            $this->dispatch();
        }
    }

    /**
     * @throws JsonException
     */
    public function dispatchUnless(bool $condition): void
    {
        if (! $condition) {
            $this->dispatch();
        }
    }

    /**
     * @throws JsonException
     */
    public function toDeliveryData(): WebhookDeliveryData
    {
        return new WebhookDeliveryData(
            messageId: $this->messageId ?? (string) Str::uuid7(),
            url: $this->url,
            rawBody: $this->resolveRawBody(),
            schemeClass: $this->schemeClass,
            backoff: $this->backoff ?? new ExponentialWithJitter,
            options: new DeliveryOptions(
                verb: $this->verb,
                connectTimeout: $this->connectTimeout,
                timeout: $this->timeout,
                verifySsl: $this->verifySsl,
                proxy: $this->proxy,
                clientCert: $this->clientCert,
                clientKey: $this->clientKey,
                clientCertPassphrase: $this->clientCertPassphrase,
                contentType: $this->contentType,
                responseCaptureBytes: $this->responseCaptureBytes,
                largePayloadThreshold: $this->largePayloadThreshold,
                respectRetryAfter: $this->respectRetryAfter,
                retryAfterCap: $this->retryAfterCap,
                retryAfterMaxDeferrals: $this->retryAfterMaxDeferrals,
            ),
            maxTries: $this->tries,
            eventType: $this->eventType,
            encryptedSecret: $this->doNotSign || ! $this->secrets instanceof SecretSet
                ? null
                : EncryptedSecretResolver::seal($this->secrets),
            doNotSign: $this->doNotSign,
            meta: $this->meta,
            tags: $this->tags,
            headers: $this->headers,
        );
    }

    /**
     * @param  Closure(CallWebhookJob): mixed  $dispatcher
     *
     * @throws JsonException
     */
    private function dispatchUsing(Closure $dispatcher): void
    {
        $data = $this->toDeliveryData();

        event(new WebhookDeliveryDispatching($data));

        $job = new CallWebhookJob($data);
        $job->onQueue($this->queue);
        $job->onConnection($this->connection);

        if ($this->delaySeconds > 0) {
            $job->delay($this->delaySeconds);
        }

        $dispatcher($job);
    }

    /**
     * @throws JsonException
     */
    private function resolveRawBody(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }

        $payload = $this->payload ?? [];

        return $this->canonicalize
            ? new JsonCanonicalizer()->canonicalize($payload)
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
