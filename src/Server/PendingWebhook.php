<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server;

use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use NoDiscard;
use Pushery\Webhooks\Core\Signing\JsonCanonicalizer;
use Pushery\Webhooks\Core\Signing\SecretSet;
use Pushery\Webhooks\Core\Signing\SignatureScheme;
use Pushery\Webhooks\Core\Signing\StandardWebhooksScheme;
use Pushery\Webhooks\Server\Backoff\BackoffStrategy;
use Pushery\Webhooks\Server\Backoff\ExponentialWithJitter;
use Pushery\Webhooks\Server\Data\DeliveryOptions;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Delivery\BearerToken;
use Pushery\Webhooks\Server\Delivery\ResolvesBearerTokens;
use Pushery\Webhooks\Server\Events\WebhookDeliveryDispatching;
use Pushery\Webhooks\Server\Exceptions\OrderedDeliveryUnavailable;
use Pushery\Webhooks\Server\Exceptions\UnknownBearerTokenResolver;
use Pushery\Webhooks\Server\Exceptions\UnknownSecretsResolver;
use Pushery\Webhooks\Server\Exceptions\UnsignedDeliveryNotChosen;
use Pushery\Webhooks\Server\Jobs\CallWebhookJob;
use Pushery\Webhooks\Server\Signing\EncryptedSecretResolver;
use Pushery\Webhooks\Server\Signing\ResolvesDeliverySecrets;
use Pushery\Webhooks\Server\Streams\StreamLedger;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Testing\FakedWebhook;
use Pushery\Webhooks\Testing\WebhookFake;

/**
 * The immutable, fluent builder for sending a webhook — the shape of Laravel's own
 * PendingRequest/PendingMail, and named for it. Each setter returns a clone, so a
 * half-built call is a reusable template. Consumers never write a service/action class
 * to send; they call this builder and react to the Pushery\Webhooks\Server\Events\* attempt
 * events via listeners.
 *
 * `Pushery\Webhooks\Server\Facades\WebhookSender::to($url)` is the same builder behind a name
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

    /** @var ?class-string<ResolvesDeliverySecrets> */
    private ?string $secretsResolver = null;

    private ?string $bearerToken = null;

    /** @var ?class-string<ResolvesBearerTokens> */
    private ?string $bearerTokenResolver = null;

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

    private bool $respectRetryAfter = true;

    private int $retryAfterCap = 900;

    private int $retryAfterMaxDeferrals = 6;

    private int $tries = 3;

    private ?int $retryForSeconds = null;

    /** @var list<string> */
    private array $retryableProblemTypes = [];

    private ?string $orderingKey = null;

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
     * HTTP verb, the timeouts, the try count or the time to keep trying, TLS verification, canonicalization, the
     * Retry-After policy, the retry schedule, the egress proxy, and the queue and
     * connection its job is pushed onto. Config is the
     * default for every delivery; the builder below overrides it per call. Seeding
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
        $call->retryForSeconds = $config->retryForSeconds();
        $call->verifySsl = $config->verifySsl();
        $call->canonicalize = $config->canonicalizeJson();
        $call->respectRetryAfter = $config->respectRetryAfter();
        // Clamped here the way the builder clamps it, and for a reason the builder does not
        // have: this value reaches $job->delay() on the deferral path, and a negative delay is
        // not a shorter wait but an invalid argument to the queue. Config is the path a host
        // reaches by typing a number into a file; the builder is the path a host reaches by
        // calling a method, and only one of the two was holding the line.
        $call->retryAfterCap = max(0, $config->retryAfterCap());
        $call->retryAfterMaxDeferrals = $config->retryAfterMaxDeferrals();
        $call->responseCaptureBytes = $config->responseCaptureBytes();
        $call->backoff = $config->backoffStrategy();
        $call->proxy = $config->egressProxy();
        $call->queue = $config->queue();
        $call->connection = $config->connection();

        // Asymmetric mode signs with the Server's own Ed25519 key, so the key travels
        // with the call from here; no per-endpoint shared secret is involved.
        $ed25519Key = $config->ed25519SigningKey();

        if ($ed25519Key !== null) {
            $call->secrets = SecretSet::fromCurrent($ed25519Key);
        }

        return $call;
    }

    #[NoDiscard]
    public function url(string $url): self
    {
        return tap(clone $this, fn (self $call): string => $call->url = $url);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    #[NoDiscard]
    public function payload(array $payload): self
    {
        return tap(clone $this, function (self $call) use ($payload): void {
            $call->payload = $payload;
            $call->rawBody = null;
        });
    }

    #[NoDiscard]
    public function sendRawBody(string $body, string $contentType = 'application/json'): self
    {
        return tap(clone $this, function (self $call) use ($body, $contentType): void {
            $call->rawBody = $body;
            $call->payload = null;
            $call->contentType = $contentType;
        });
    }

    /**
     * Canonicalize the JSON payload (sorted keys) before signing, so signed and
     * sent bytes stay identical for a receiver that re-canonicalizes. Opt-in.
     */
    #[NoDiscard]
    public function canonicalizeJson(bool $canonicalize = true): self
    {
        return tap(clone $this, fn (self $call): bool => $call->canonicalize = $canonicalize);
    }

    #[NoDiscard]
    public function useSecret(string $secret): self
    {
        return tap(clone $this, fn (self $call): SecretSet => $call->secrets = SecretSet::fromCurrent($secret));
    }

    #[NoDiscard]
    public function useSecrets(string $current, string $previous): self
    {
        return tap(clone $this, fn (self $call): SecretSet => $call->secrets = SecretSet::rotating($current, $previous));
    }

    /**
     * Sign every attempt with the secrets this class names at that moment, instead of the secrets of this call.
     *
     * The delivery then carries no secret in the queue: each attempt, and each resend, makes the class from the container
     * and asks it, so a receiver that rotates its secret while the delivery waits gets the new one on the next attempt.
     * It takes the place of `useSecret()` and `useSecrets()`, and `doNotSign()` still sends unsigned.
     *
     * @param  class-string  $resolver  a class implementing ResolvesDeliverySecrets; any other class is refused with
     *                                  UnknownSecretsResolver
     */
    #[NoDiscard]
    public function resolveSecretsUsing(string $resolver): self
    {
        if (! is_a($resolver, ResolvesDeliverySecrets::class, true)) {
            throw UnknownSecretsResolver::for($resolver);
        }

        return tap(clone $this, fn (self $call): string => $call->secretsResolver = $resolver);
    }

    /**
     * Send `Authorization: Bearer` with this token on every attempt, beside the signature, for a receiver behind an
     * API gateway that asks each request for a key it issued.
     *
     * The token travels sealed with the application key and is unsealed only when an attempt is sent, so it never
     * stands in cleartext in the queue, in the outbox or in an event. Every attempt sends this token, one after the
     * receiver replaced it included; to send the key that holds at each attempt, name a class with
     * `resolveBearerTokenUsing()` instead. A token with a space, a tab, a line break or a control character is
     * refused with an InvalidArgumentException.
     */
    #[NoDiscard]
    public function useBearerToken(string $token): self
    {
        BearerToken::header($token);

        return tap(clone $this, fn (self $call): string => $call->bearerToken = $token);
    }

    /**
     * Send `Authorization: Bearer` with the token this class names at each attempt, instead of the token of this
     * call.
     *
     * The delivery then carries no token in the queue: each attempt, and each resend, makes the class from the
     * container and asks it, so an attempt after the receiver replaced its key sends the new one. It takes the place
     * of `useBearerToken()`.
     *
     * @param  class-string  $resolver  a class implementing ResolvesBearerTokens; any other class is refused with
     *                                  UnknownBearerTokenResolver
     */
    #[NoDiscard]
    public function resolveBearerTokenUsing(string $resolver): self
    {
        if (! is_a($resolver, ResolvesBearerTokens::class, true)) {
            throw UnknownBearerTokenResolver::for($resolver);
        }

        return tap(clone $this, fn (self $call): string => $call->bearerTokenResolver = $resolver);
    }

    #[NoDiscard]
    public function doNotSign(bool $doNotSign = true): self
    {
        return tap(clone $this, fn (self $call): bool => $call->doNotSign = $doNotSign);
    }

    /**
     * @param  class-string<SignatureScheme>  $scheme
     */
    #[NoDiscard]
    public function signUsing(string $scheme): self
    {
        return tap(clone $this, fn (self $call): string => $call->schemeClass = $scheme);
    }

    /**
     * Pin a stable message id (the Standard Webhooks `webhook-id`). The Platform
     * layer passes its event id here so a redelivery re-signs with the same id and
     * the DB event_id equals the wire id.
     */
    #[NoDiscard]
    public function useMessageId(string $messageId): self
    {
        return tap(clone $this, fn (self $call): string => $call->messageId = $messageId);
    }

    #[NoDiscard]
    public function forEventType(?string $eventType): self
    {
        return tap(clone $this, fn (self $call): ?string => $call->eventType = $eventType);
    }

    #[NoDiscard]
    public function useHttpVerb(string $verb): self
    {
        return tap(clone $this, fn (self $call): string => $call->verb = strtolower($verb));
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[NoDiscard]
    public function withHeaders(array $headers): self
    {
        return tap(clone $this, fn (self $call): array => $call->headers = [...$call->headers, ...$headers]);
    }

    /**
     * At least one second. To the HTTP client a timeout of 0 means no timeout at all, the
     * opposite of what a caller asking for none expects, and a negative one means nothing.
     */
    #[NoDiscard]
    public function timeoutInSeconds(int $timeout): self
    {
        return tap(clone $this, fn (self $call): int => $call->timeout = max(1, $timeout));
    }

    /**
     * At least one second, for the reason {@see self::timeoutInSeconds()} gives.
     */
    #[NoDiscard]
    public function connectTimeoutInSeconds(int $connectTimeout): self
    {
        return tap(clone $this, fn (self $call): int => $call->connectTimeout = max(1, $connectTimeout));
    }

    #[NoDiscard]
    public function verifySsl(bool|string $verify = true): self
    {
        return tap(clone $this, fn (self $call): bool|string => $call->verifySsl = $verify);
    }

    #[NoDiscard]
    public function useProxy(string $proxy): self
    {
        return tap(clone $this, fn (self $call): string => $call->proxy = $proxy);
    }

    #[NoDiscard]
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
    #[NoDiscard]
    public function respectRetryAfter(bool $respect = true): self
    {
        return tap(clone $this, fn (self $call): bool => $call->respectRetryAfter = $respect);
    }

    /**
     * The longest Retry-After wait this queue can hold a job for. A hint beyond it is
     * waited out at the cap without charging the delivery's retry budget, up to
     * $maxDeferrals times, so a long rate-limit window cannot exhaust the delivery
     * before the endpoint is ready for it.
     */
    #[NoDiscard]
    public function retryAfterCapInSeconds(int $seconds, ?int $maxDeferrals = null): self
    {
        return tap(clone $this, function (self $call) use ($seconds, $maxDeferrals): void {
            $cap = max(0, $seconds);
            $call->retryAfterCap = $cap;
            $call->retryAfterMaxDeferrals = max(0, $maxDeferrals ?? $call->retryAfterMaxDeferrals);

            // Keep the schedule's Retry-After clamp in lockstep with the cap. The defer
            // threshold (above) and the delay clamp are the same wait; the builder must
            // move both, or a hint under the new cap would still be shortened to the old
            // one — released early, budget charged, back while the endpoint is still
            // rate-limiting. Config seeds both equal; only this builder can split them. A
            // custom strategy owns its own clamp, so it is left untouched.
            if ($call->backoff instanceof ExponentialWithJitter) {
                $call->backoff = $call->backoff->withRetryAfterCap($cap);
            }
        });
    }

    /**
     * How many bytes of the endpoint's response are captured for the delivery log. The
     * rest is read off the wire and discarded, never buffered.
     */
    #[NoDiscard]
    public function captureResponseBytes(int $bytes): self
    {
        return tap(clone $this, fn (self $call): int => $call->responseCaptureBytes = max(0, $bytes));
    }

    #[NoDiscard]
    public function maximumTries(int $tries): self
    {
        return tap(clone $this, fn (self $call): int => $call->tries = max(1, $tries));
    }

    /**
     * Keep retrying for this many seconds instead of a number of tries: with the same backoff,
     * until the time is up, and the last attempt on that moment. Then the delivery is exhausted
     * like any other, and `DeliveryStreams::resend()` gives it the whole time again. The time
     * starts when the delivery is due: after its delay, and for an ordered delivery once its
     * turn has come. At least one second; null counts tries again.
     */
    #[NoDiscard]
    public function retryForSeconds(?int $seconds): self
    {
        return tap(clone $this, fn (self $call): ?int => $call->retryForSeconds = $seconds === null ? null : max(1, $seconds));
    }

    /**
     * Retry a 4xx answer whose body is an RFC 9457 problem document of one of these types, the
     * way a 5xx is retried: with the delivery's backoff, within its tries or its time to keep
     * trying. For an endpoint that answers "not now" with a status that otherwise means "never",
     * such as a paused account behind a 403. Every other 4xx keeps its classification, and so
     * does a body cut at the capture limit. A type is a URI and is compared as written; an empty
     * list retries no 4xx by its type again.
     *
     * @param  list<string>  $types
     */
    #[NoDiscard]
    public function retryableProblemTypes(array $types): self
    {
        foreach ($types as $type) {
            if (trim($type) === '') {
                throw new InvalidArgumentException('A problem type is the `type` of a problem document, a URI, so it cannot be empty.');
            }
        }

        return tap(clone $this, fn (self $call): array => $call->retryableProblemTypes = array_values(array_unique($types)));
    }

    /**
     * Send this delivery in order with every other delivery to the same URL under the same key:
     * one at a time, in the order they were dispatched, each only after the one before it
     * succeeded. A delivery that ends without success holds the stream until it is resent or
     * skipped through {@see DeliveryStreams}. Deliveries under other keys are not held up.
     *
     * Needs `webhooks.server.ordering.enabled` and a queue connection that can hold a job back;
     * dispatching without either throws {@see OrderedDeliveryUnavailable}. Null sends the
     * delivery on its own again.
     */
    #[NoDiscard]
    public function orderedBy(?string $key): self
    {
        if ($key !== null && trim($key) === '') {
            throw new InvalidArgumentException('An ordering key names the stream a delivery goes out in, so it cannot be empty. Pass null for a delivery that goes out on its own.');
        }

        return tap(clone $this, fn (self $call): ?string => $call->orderingKey = $key);
    }

    #[NoDiscard]
    public function useBackoffStrategy(BackoffStrategy $backoff): self
    {
        return tap(clone $this, fn (self $call): BackoffStrategy => $call->backoff = $backoff);
    }

    /**
     * @param  array<string, scalar|null>  $meta
     */
    #[NoDiscard]
    public function meta(array $meta): self
    {
        return tap(clone $this, fn (self $call): array => $call->meta = [...$call->meta, ...$meta]);
    }

    /**
     * @param  list<string>  $tags
     */
    #[NoDiscard]
    public function withTags(array $tags): self
    {
        return tap(clone $this, fn (self $call): array => $call->tags = [...$call->tags, ...$tags]);
    }

    /**
     * Hold the delivery in the queue for this many seconds before its first attempt —
     * how the Platform layer shapes an over-limit endpoint's traffic instead of
     * dropping the event.
     */
    #[NoDiscard]
    public function delayInSeconds(int $seconds): self
    {
        // The dispatch reads `if ($this->delaySeconds > 0)`, so 0 and -1 both mean "queue it
        // now".
        //
        // The clamp stays because the property should not carry a value the sender never
        // meant. A negative hold stored as a negative number is one refactor away from being
        // passed to a queue driver that reads it literally.
        return tap(clone $this, fn (self $call): int => $call->delaySeconds = max(0, $seconds));
    }

    /** The seconds the delivery waits before it is due, as {@see self::delayInSeconds()} set them; 0 for at once. */
    public function delaySeconds(): int
    {
        return $this->delaySeconds;
    }

    #[NoDiscard]
    public function onQueue(?string $queue): self
    {
        return tap(clone $this, fn (self $call): ?string => $call->queue = $queue);
    }

    #[NoDiscard]
    public function onConnection(?string $connection): self
    {
        return tap(clone $this, fn (self $call): ?string => $call->connection = $connection);
    }

    /**
     * Queue the delivery and return the dispatched {@see WebhookDeliveryData} — its messageId
     * (stable across attempts) lets a Server-only caller, which has no Platform delivery row,
     * correlate the send with its own log and any later status callback.
     *
     * @throws JsonException
     */
    public function dispatch(): WebhookDeliveryData
    {
        return $this->dispatchUsing(dispatch(...));
    }

    /**
     * Send the delivery on the current process and return the dispatched delivery data. An
     * ordered delivery cannot be sent this way, because it waits for its turn in the queue.
     *
     * @throws JsonException
     * @throws OrderedDeliveryUnavailable
     */
    public function dispatchSync(): WebhookDeliveryData
    {
        if ($this->orderingKey !== null) {
            throw OrderedDeliveryUnavailable::dispatchedSync();
        }

        return $this->dispatchUsing(dispatch_sync(...));
    }

    /**
     * Dispatch only when the condition holds; returns the dispatched delivery data, or null when
     * the condition is false and nothing was sent.
     *
     * @throws JsonException
     */
    public function dispatchIf(bool $condition): ?WebhookDeliveryData
    {
        if ($condition) {
            return $this->dispatch();
        }

        return null;
    }

    /**
     * Dispatch unless the condition holds; returns the dispatched delivery data, or null when the
     * condition is true and nothing was sent.
     *
     * @throws JsonException
     */
    public function dispatchUnless(bool $condition): ?WebhookDeliveryData
    {
        return $condition ? null : $this->dispatch();
    }

    /**
     * The delivery this call describes, as the queue and the outbox carry it.
     *
     * A call that names no secret, no secrets resolver and no doNotSign() would go out unsigned although nobody chose
     * that, so it is reported to the exception handler, or refused under `webhooks.server.signing.unsigned` =
     * `refuse`. Under asymmetric signing the call already carries the Server's own key.
     *
     * @throws JsonException
     * @throws UnsignedDeliveryNotChosen where the configuration refuses a delivery nobody chose to send unsigned
     */
    public function toDeliveryData(): WebhookDeliveryData
    {
        if (! $this->doNotSign && $this->secretsResolver === null && ! $this->secrets instanceof SecretSet) {
            $unsigned = UnsignedDeliveryNotChosen::to((string) parse_url($this->url, PHP_URL_HOST));

            if (new Settings()->unsignedDeliveries() === 'refuse') {
                throw $unsigned;
            }

            report($unsigned);
        }

        // Two `Authorization` headers would leave the receiver to pick one, and the one from withHeaders() would
        // stand in cleartext in the queue beside the sealed token.
        if (($this->bearerToken !== null || $this->bearerTokenResolver !== null) && $this->namesAuthorization()) {
            throw new InvalidArgumentException('A delivery that sends a bearer token takes no Authorization header of its own: withHeaders() named one beside useBearerToken() or resolveBearerTokenUsing().');
        }

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
                // Sealed for the same reason as the passphrase below: a proxy that authenticates
                // carries its credentials in the userinfo of its URL, so the URL is a credential.
                proxy: $this->proxy === null ? null : Crypt::encryptString($this->proxy),
                clientCert: $this->clientCert,
                clientKey: $this->clientKey,
                // Seal the mutual-TLS passphrase like the signing secret: it must not sit in
                // cleartext in the queue store or in an attempt-event payload. It is unsealed
                // at send time in DeliveryOptions::toTransportOptions().
                clientCertPassphrase: $this->clientCertPassphrase === null
                    ? null
                    : Crypt::encryptString($this->clientCertPassphrase),
                contentType: $this->contentType,
                responseCaptureBytes: $this->responseCaptureBytes,
                respectRetryAfter: $this->respectRetryAfter,
                retryAfterCap: $this->retryAfterCap,
                retryAfterMaxDeferrals: $this->retryAfterMaxDeferrals,
            ),
            maxTries: $this->tries,
            eventType: $this->eventType,
            encryptedSecret: $this->doNotSign || $this->secretsResolver !== null || ! $this->secrets instanceof SecretSet
                ? null
                : EncryptedSecretResolver::seal($this->secrets),
            doNotSign: $this->doNotSign,
            meta: $this->meta,
            tags: $this->tags,
            headers: $this->headers,
            orderingKey: $this->orderingKey,
            retryForSeconds: $this->retryForSeconds,
            retryUntil: $this->retryForSeconds === null
                ? null
                : Date::now()->getTimestamp() + $this->delaySeconds + $this->retryForSeconds,
            retryableProblemTypes: $this->retryableProblemTypes,
            secretsResolver: $this->doNotSign ? null : $this->secretsResolver,
            encryptedBearerToken: $this->bearerTokenResolver !== null || $this->bearerToken === null
                ? null
                : Crypt::encryptString($this->bearerToken),
            bearerTokenResolver: $this->bearerTokenResolver,
        );
    }

    /** Whether the headers of this call name `Authorization`, in any case. */
    private function namesAuthorization(): bool
    {
        return array_any(array_keys($this->headers), static fn (int|string $name): bool => strcasecmp((string) $name, 'Authorization') === 0);
    }

    /**
     * @param  Closure(CallWebhookJob): mixed  $dispatcher
     *
     * @throws JsonException
     */
    private function dispatchUsing(Closure $dispatcher): WebhookDeliveryData
    {
        $data = $this->toDeliveryData();
        $stream = $data->stream();

        if ($stream !== null) {
            StreamLedger::assertCanOrder($this->connection, $this->queue);
        }

        // A test that switched the fake on records the delivery here, after the refusals above
        // and before anything is announced or queued.
        $fake = WebhookFake::active();

        if ($fake instanceof WebhookFake) {
            $fake->record(FakedWebhook::sentThroughTheServer($data));

            return $data;
        }

        event(new WebhookDeliveryDispatching($data));

        $job = new CallWebhookJob($data);
        $job->onQueue($this->queue);
        $job->onConnection($this->connection);

        if ($this->delaySeconds > 0) {
            $job->delay($this->delaySeconds);
        }

        if ($stream === null) {
            $dispatcher($job);
        } else {
            StreamLedger::queueInStream($stream, $data->messageId, static function () use ($job, $dispatcher): void {
                $dispatcher($job);
            });
        }

        // Return the exact data that was dispatched — its messageId (stable across attempts) is
        // what a Server-only caller correlates against its own log and any later status callback.
        return $data;
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
