<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Pushery\Webhooks\Client\Dedupe\DedupeKeyResolver;
use Pushery\Webhooks\Client\Exceptions\WebhookConfigCannotVerify;
use Pushery\Webhooks\Client\Http\BodyDecoder;
use Pushery\Webhooks\Client\Jobs\ProcessWebhookJob;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Client\Profiles\ProcessEverythingWebhookProfile;
use Pushery\Webhooks\Client\Profiles\WebhookProfile;
use Pushery\Webhooks\Client\Responses\DefaultRespondsTo;
use Pushery\Webhooks\Client\Responses\RespondsToWebhook;
use Pushery\Webhooks\Client\Verification\InboundVerifier;
use Pushery\Webhooks\Core\Signing\AcceptsSignatureHeaders;
use Pushery\Webhooks\Core\Signing\Ed25519Scheme;
use Pushery\Webhooks\Core\Signing\GitHubScheme;
use Pushery\Webhooks\Core\Signing\Jwks\JwksKeySet;
use Pushery\Webhooks\Core\Signing\PlainHmacScheme;
use Pushery\Webhooks\Core\Signing\SecretSet;
use Pushery\Webhooks\Core\Signing\SignatureHeaders;
use Pushery\Webhooks\Core\Signing\SignatureScheme;
use Pushery\Webhooks\Core\Signing\StandardWebhooksScheme;
use Pushery\Webhooks\Core\Signing\StripeScheme;
use Pushery\Webhooks\Core\Signing\StripeStyleScheme;

/**
 * A typed, resolved view of a single webhooks.client.configs entry, selected by
 * name. Every accessor returns a fully-typed object with the entry's value or the
 * documented default, so the pipeline never juggles raw mixed config. scheme =>
 * 'auto' resolves to the Server layer's default {@see StandardWebhooksScheme}, so
 * an app receives its own deliveries with no extra plumbing.
 */
final class WebhookConfig
{
    /**
     * The built-in dialects whose wire format carries no timestamp, so a verification through
     * them can never return expired and `tolerance_seconds` is inert.
     *
     * A hand-written list, and therefore held by a test that derives the same answer from
     * BEHAVIOR rather than from this line: every built-in scheme signs a message and verifies
     * it back with a tolerance of -1, which makes even a fresh signature expired for anything
     * that checks a window. The ones that still return valid are exactly these two, and the
     * test fails if a new scheme joins them or one of these grows a window.
     *
     * A host's own scheme cannot be classified from here, so it is left alone rather than
     * guessed at: a warning that names someone's correct code is worse than none.
     *
     * @var list<class-string<SignatureScheme>>
     */
    private const array SCHEMES_WITHOUT_A_REPLAY_WINDOW = [
        GitHubScheme::class,
        PlainHmacScheme::class,
    ];

    /**
     * The built-in dialects that send no delivery-id header, so with `dedupe_id` unset the
     * idempotency key is null — and a null collides with nothing, in the partial unique index
     * and in the cache fast path alike.
     *
     * A superset of the list above and a different question. A dialect can carry a timestamp
     * and no id (Stripe: the `evt_…` is in the body), which bounds a replay in TIME without
     * making it idempotent — inside the tolerance window the same delivery is processed as
     * often as it arrives.
     *
     * Derived by the same test and the same way: every built-in scheme signs a message, and
     * the ones whose headers come back without the configured id header are exactly these.
     *
     * @var list<class-string<SignatureScheme>>
     */
    private const array SCHEMES_WITHOUT_A_DELIVERY_ID_HEADER = [
        GitHubScheme::class,
        PlainHmacScheme::class,
        StripeStyleScheme::class,
        StripeScheme::class,
    ];

    /**
     * @param  class-string<SignatureScheme>  $schemeClass
     * @param  array{id?: string, timestamp?: string, signature?: string}  $explicitHeaders
     * @param  class-string<WebhookProfile>  $profileClass
     * @param  class-string<RespondsToWebhook>  $responseClass
     * @param  class-string<WebhookCall>  $modelClass
     * @param  class-string<ProcessWebhookJob>|array<string, class-string<ProcessWebhookJob>>|null  $process
     * @param  list<string>  $redact
     * @param  '*'|list<string>  $storeHeaders
     * @param  array{enabled: bool, threshold: int, disk: string}  $largePayload
     * @param  array{max_attempts: int, decay_seconds: int}|null  $rateLimit
     */
    public function __construct(
        public string $name,
        private readonly string $secret,
        private readonly ?string $previousSecret,
        private readonly string $schemeClass,
        private readonly ?string $verifierClass,
        private readonly string $idHeader,
        private readonly string $timestampHeader,
        private readonly string $signatureHeader,
        private readonly array $explicitHeaders,
        private readonly int $tolerance,
        private readonly int $invalidStatus,
        private readonly ?int $undeterminedStatus,
        private readonly string $profileClass,
        private readonly string $responseClass,
        private readonly string $modelClass,
        private string|array|null $process,
        private readonly array $redact,
        private readonly string|array $storeHeaders,
        private readonly string $dedupe,
        private readonly ?string $dedupeId,
        private readonly ?string $eventTypeSpec,
        private readonly ?string $jwksUrl,
        private readonly int $jwksCacheTtl,
        private readonly ?string $jwksKid,
        private readonly array $largePayload,
        private readonly ?array $rateLimit,
    ) {}

    /**
     * Resolve the config entry whose 'name' matches, throwing when none is defined.
     */
    public static function forName(string $name): self
    {
        foreach (Config::array('webhooks.client.configs', []) as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return self::fromEntry($name, $entry);
            }
        }

        throw new InvalidArgumentException("No webhook client config named [{$name}] is defined in webhooks.client.configs.");
    }

    /**
     * Every fault in webhooks.client.configs, one message each, empty when there are none.
     *
     * This is what turns a class of defect that is invisible until the first real delivery
     * into one a deploy can see. A config with no verification material, a misspelled
     * driver, a verifier that is not an InboundVerifier: none of it is reachable by a test
     * of the host's own code, nothing renders differently, and the first symptom is a
     * delivery that was refused or lost — weeks after the deployment that caused it.
     *
     * It does not restate the rules. It BUILDS each entry through the same path a request
     * takes and collects what that refuses, so a rule added to the builder is covered here
     * the day it lands, and a second copy of the rules can never drift from the first.
     *
     * Public because a host wiring its own health check is exactly the intended use, next
     * to `webhooks:preflight`, which is this method with a console around it.
     *
     * @return list<string>
     */
    public static function configurationFaults(): array
    {
        $faults = [];
        $seen = [];

        foreach (Config::array('webhooks.client.configs', []) as $index => $entry) {
            $at = "webhooks.client.configs.{$index}";

            if (! is_array($entry)) {
                $faults[] = "The webhook client config at [{$at}] is not an array.";

                continue;
            }

            $name = $entry['name'] ?? null;

            if (! is_string($name) || $name === '') {
                // Unreachable rather than broken: forName() matches on 'name', so an entry
                // without one is never selected and the route pointing at it answers "no
                // config named [...]" — a message that sends the reader looking for a
                // MISSING entry rather than at the one sitting right there.
                $faults[] = "The webhook client config at [{$at}] has no non-empty 'name', so no route can ever resolve it.";

                continue;
            }

            if (isset($seen[$name])) {
                // forName() returns the first match and stops. The second entry is dead
                // config that reads as live, which is worse than absent: a secret rotated
                // in the wrong one of two identically named entries changes nothing at all.
                $faults[] = "The webhook client config name [{$name}] is defined more than once; only the first is ever used.";

                continue;
            }

            $seen[$name] = true;

            try {
                self::fromEntry($name, $entry);
            } catch (InvalidArgumentException $fault) {
                $faults[] = $fault->getMessage();
            }
        }

        return $faults;
    }

    /**
     * Sources whose authentic deliveries nothing makes idempotent — one advisory line each, in
     * the shape {@see self::configurationFaults()} uses, so the preflight can print them the same way.
     *
     * These are not faults. Every combination named here is a legal, working configuration; what
     * it lacks is a replay boundary, and neither half of the lack is visible from the config file.
     * `tolerance_seconds` sits right there in the entry and reads like protection even for a
     * dialect that has no timestamp to check it against, and the dedupe default is a HEADER the
     * producer may simply not send — a key that stays null, and a null collides with nothing.
     *
     * @return list<string>
     */
    public static function replayBoundaryAdvisories(): array
    {
        $advisories = [];
        $seen = [];

        foreach (Config::array('webhooks.client.configs', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = $entry['name'] ?? null;

            // Both are already reported by configurationFaults(), which the preflight runs
            // first and fails on. Repeating them here would say the same thing twice in two
            // registers, and an advisory next to an error reads as a second, lesser error.
            if (! is_string($name) || $name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            try {
                $config = self::fromEntry($name, $entry);
            } catch (InvalidArgumentException) {
                continue;
            }

            $scheme = $config->schemeWithoutDeliveryIdHeader();

            if ($scheme === null) {
                continue;
            }

            // Two facts, one message. The id half is why we are here at all, so it leads; the
            // window half only sharpens it. Split across two advisories, a GitHub source would
            // draw both and a reader would fix one and think the source was covered.
            $advisories[] = sprintf(
                'Webhook source [%s] verifies with %s, a dialect that sends no delivery-id '
                .'header: with no dedupe_id set the key is read from [%s], which this producer '
                .'does not send, so it stays null — and a null collides with nothing. Both '
                .'dedupe tiers are inert for this source, and every retry the producer makes '
                .'stores another row and runs the handler again.%s Set dedupe_id to the id the '
                .'producer does send (GitHub: header:X-GitHub-Delivery; Stripe: body:id — the '
                .'envelope, not data.object.id).',
                $name,
                class_basename($scheme),
                $config->idHeader,
                $config->schemeWithoutReplayWindow() === null
                    ? ''
                    : sprintf(
                        ' This dialect also signs no timestamp, so its tolerance_seconds (%d) is '
                        .'never consulted and nothing bounds a replay in time either.',
                        $config->tolerance,
                    ),
            );
        }

        return $advisories;
    }

    /**
     * The scheme class when this source's dialect sends no delivery-id header and nothing else
     * supplies the key, null otherwise.
     *
     * `idHeader` defaults to the Standard Webhooks name whatever the scheme is, so an unset
     * `dedupe_id` looks for a header four of the six built-in dialects never send. A configured
     * `dedupe_id` answers it, and a 'verifier' puts the whole question out of scope.
     */
    public function schemeWithoutDeliveryIdHeader(): ?string
    {
        if ($this->verifierClass !== null || $this->dedupeId !== null) {
            return null;
        }

        return in_array($this->schemeClass, self::SCHEMES_WITHOUT_A_DELIVERY_ID_HEADER, true)
            ? $this->schemeClass
            : null;
    }

    /**
     * The scheme class when this source has no replay boundary at all, null when it has one.
     *
     * A dialect with a signed timestamp bounds a replay with `tolerance_seconds`; the two that
     * carry none say so in their own docblocks, and a verification through them never returns
     * expired. A configured `dedupe_id` is the other boundary, and it is enough on its own — so
     * this asks for the absence of BOTH.
     *
     * A 'verifier' takes precedence over the scheme entirely and reaches its verdict however it
     * likes, so the question does not apply to one and no advice is offered about it.
     */
    public function schemeWithoutReplayWindow(): ?string
    {
        if ($this->verifierClass !== null || $this->dedupeId !== null) {
            return null;
        }

        return in_array($this->schemeClass, self::SCHEMES_WITHOUT_A_REPLAY_WINDOW, true)
            ? $this->schemeClass
            : null;
    }

    /**
     * The verification key material. A 'jwks' url resolves the producer's Ed25519
     * public keys through the SSRF-guarded, cached {@see JwksKeySet}; otherwise the
     * static configured secret (a shared HMAC secret, or a base64 Ed25519 public key)
     * is used, with the previous secret added during a rotation window.
     */
    public function secrets(): SecretSet
    {
        if ($this->jwksUrl !== null) {
            return app(JwksKeySet::class)->secretSet($this->jwksUrl, $this->jwksCacheTtl, $this->jwksKid);
        }

        return $this->previousSecret === null
            ? SecretSet::fromCurrent($this->secret)
            : SecretSet::rotating($this->secret, $this->previousSecret);
    }

    public function scheme(): SignatureScheme
    {
        if ($this->schemeClass === StandardWebhooksScheme::class) {
            return new StandardWebhooksScheme($this->idHeader, $this->timestampHeader, $this->signatureHeader);
        }

        if ($this->schemeClass === Ed25519Scheme::class) {
            return new Ed25519Scheme($this->idHeader, $this->timestampHeader, $this->signatureHeader);
        }

        $scheme = app()->make($this->schemeClass);

        if (! $scheme instanceof SignatureScheme) {
            throw new InvalidArgumentException("The scheme for webhook client config [{$this->name}] did not resolve to a SignatureScheme.");
        }

        // Any other scheme is resolved from the container with no arguments, so it carries
        // its own default header names. Hand it the ones the host EXPLICITLY configured (a
        // null keeps the scheme's own default), so a provider that uses a different header —
        // PlainHmacScheme for SendCloud's 'Sendcloud-Signature', say — works from config
        // alone instead of forcing the host to write a scheme class just to bind the header.
        return $scheme instanceof AcceptsSignatureHeaders
            ? $scheme->withSignatureHeaders(
                $this->explicitHeaders['id'] ?? null,
                $this->explicitHeaders['timestamp'] ?? null,
                $this->explicitHeaders['signature'] ?? null,
            )
            : $scheme;
    }

    /**
     * The custom inbound verifier for this source, or null to fall back to the signature
     * scheme. When set it takes precedence over {@see self::scheme()} and authenticates
     * the request itself (an API callback, a cert chain) — so 'secret' is optional. The
     * pipeline calls this first; only its absence reaches the scheme.
     */
    public function verifier(): ?InboundVerifier
    {
        if ($this->verifierClass === null) {
            return null;
        }

        $verifier = app()->make($this->verifierClass);

        return $verifier instanceof InboundVerifier
            ? $verifier
            : throw new InvalidArgumentException("The verifier for webhook client config [{$this->name}] did not resolve to an InboundVerifier.");
    }

    public function tolerance(): int
    {
        return $this->tolerance;
    }

    public function invalidStatus(): int
    {
        return $this->invalidStatus;
    }

    /**
     * The status for a verification that did not complete, defaulting to invalid_status.
     *
     * Configured separately, and unset by default, because it is the one refusal a producer
     * could usefully retry: a `503` tells the sender "ask again", a `401` tells it "give
     * up". Splitting them is a decision only the host can make — it changes what the caller
     * is told — so an installation that configures nothing keeps answering every refusal
     * identically, exactly as before.
     */
    public function undeterminedStatus(): int
    {
        return $this->undeterminedStatus ?? $this->invalidStatus;
    }

    public function profile(): WebhookProfile
    {
        $profile = app()->make($this->profileClass);

        return $profile instanceof WebhookProfile
            ? $profile
            : throw new InvalidArgumentException("The profile for webhook client config [{$this->name}] did not resolve to a WebhookProfile.");
    }

    public function response(): RespondsToWebhook
    {
        $response = app()->make($this->responseClass);

        return $response instanceof RespondsToWebhook
            ? $response
            : throw new InvalidArgumentException("The response for webhook client config [{$this->name}] did not resolve to a RespondsToWebhook.");
    }

    /**
     * @return class-string<WebhookCall>
     */
    public function model(): string
    {
        return $this->modelClass;
    }

    /**
     * The delivery's idempotency key — what the partial-unique store and the fast-path
     * cache dedupe on — or null when this producer offers none (the call is then always
     * stored). The 'dedupe_id' config selects where it comes from:
     *
     *   - unset          the configured id header (the Standard-Webhooks default)
     *   - 'header:Name'  an arbitrary header
     *   - 'body:path'    a dotted path into the decoded body (Stripe's evt_… lives there,
     *                    and so does Mollie's tr_… — a form field, not a JSON one)
     *   - a class-string a {@see DedupeKeyResolver} the container resolves
     *
     * The body forms exist because real providers (Stripe, Mollie, SendCloud) carry no
     * delivery-id header, so a header-only key stays null — and a null never collides with
     * the partial-unique index, so dedupe silently does nothing for exactly those producers.
     */
    public function webhookId(SignatureHeaders $headers, string $rawBody = ''): ?string
    {
        return $this->boundedWebhookId($this->resolvedWebhookId($headers, $rawBody));
    }

    /**
     * Bound the dedupe key to the column that stores it — by HASHING, not by truncating.
     *
     * `webhook_id` is `varchar(255)` and has the same exposure `event_type` had: the row is
     * written AFTER the signature verifies and BEFORE the 2xx goes back, so an over-long
     * value fails the insert, the request answers 500, and the producer retries into the
     * same failure until its budget runs out. The delivery was authentic, it was accepted,
     * and then it was gone.
     *
     * The fix its twin got would be worse here than the defect. This column is the dedupe key: it
     * sits in a partial unique index over (source, webhook_id). Truncating makes two different
     * producer ids that share a 255-character prefix into one key, and a duplicate is dropped
     * without a sound. That trades a loud 500 for a silent lost delivery, which is the wrong half
     * of the trade. `event_type` can be cut because it is a routing value: a truncated type matches
     * no `process` map entry and lands on the catch-all, which is exactly where an over-long type
     * was going anyway.
     *
     * Hashing keeps what the column is for. The same long id hashes to the same key, so the
     * producer's retry still deduplicates; two different ids do not collide, prefix or not.
     * The stored value is no longer the producer's own string — which is why it is PREFIXED:
     * an operator holding this against a producer log sees `sha256:…` and knows to hash
     * theirs rather than concluding the ids do not match.
     *
     * The prefix is also what keeps the substitution honest in the other direction. A key
     * that is already short passes through untouched, so the only way a stored `sha256:…`
     * can appear is this branch — unless a producer sends that literal shape itself, which
     * would have to be the hash of one of its own over-long ids to collide with anything.
     */
    private function boundedWebhookId(?string $value): ?string
    {
        // Characters, not bytes, and the same 255 the twin uses: varchar(255) counts
        // characters on PostgreSQL and on MySQL under utf8mb4 alike.
        if ($value === null || mb_strlen($value) <= 255) {
            return $value;
        }

        return 'sha256:'.hash('sha256', $value);
    }

    private function resolvedWebhookId(SignatureHeaders $headers, string $rawBody): ?string
    {
        $spec = $this->dedupeId;

        if ($spec === null) {
            return $this->nonEmpty($headers->get($this->idHeader));
        }

        if (str_starts_with($spec, 'header:')) {
            return $this->nonEmpty($headers->get(substr($spec, 7)));
        }

        if (str_starts_with($spec, 'body:')) {
            $value = data_get($this->decodeBody($rawBody, $headers), substr($spec, 5));

            return match (true) {
                is_string($value) => $this->nonEmpty($value),
                is_int($value) => (string) $value,
                default => null,
            };
        }

        $resolver = app()->make($spec);

        return $resolver instanceof DedupeKeyResolver
            ? $this->nonEmpty($resolver->resolve($this->decodeBody($rawBody, $headers), $rawBody, $headers))
            : throw new InvalidArgumentException("The 'dedupe_id' resolver for webhook client config [{$this->name}] did not resolve to a DedupeKeyResolver.");
    }

    /**
     * The delivery's event type — the column a stream is split on, and what picks the
     * handler job. The 'event_type' config selects where it comes from:
     *
     *   - unset          the body's own `type` field (the default, and what a producer
     *                    following the Standard Webhooks envelope sends)
     *   - 'header:Name'  an arbitrary header
     *   - 'body:path'    a dotted path into the decoded body
     *   - a class-string an {@see EventTypeResolver} the container resolves
     *
     * The header form exists because real producers put it there and nowhere else: GitHub
     * sends `X-GitHub-Event`, and its body carries only `action` — the sub-kind. Read from
     * the body alone, such an installation logs every delivery with an EMPTY type, so the
     * generated column is empty too and per-type job routing falls to the catch-all every
     * time. Nothing goes red; the stream simply cannot be split.
     *
     * The resolver form is the one GitHub actually wants, because the useful type is the
     * header AND `action` together (`release.published`) rather than either half.
     *
     * Deliberately the same grammar as 'dedupe_id', so one validator, one set of prefixes,
     * and one thing to learn.
     */
    public function eventTypeFor(SignatureHeaders $headers, string $rawBody, ?string $bodyType): ?string
    {
        return $this->boundedEventType($this->resolvedEventType($headers, $rawBody, $bodyType));
    }

    /**
     * Bound an event type to the column that stores it.
     *
     * `event_type` is `varchar(255)` on both engines, and since v2.0.0 the value can come
     * straight from a producer's header. The write happens AFTER the signature verifies and
     * BEFORE the 2xx goes back, so an over-long value does not truncate — it fails the
     * insert, answers 500, and the producer retries into the same failure until its budget
     * runs out. The delivery is authentic, it was accepted, and it is lost.
     *
     * Truncating is the same lossy-but-valid trade the stored payload makes for NUL bytes:
     * the delivery survives, the exact bytes stay in the raw body, and a value this long
     * routes to the catch-all either way — no 'process' map key is 255 characters long.
     *
     * The dedupe key must not get this treatment, which is why the bound lives here and not in
     * nonEmpty() where both paths meet. `webhook_id` is the same width and has the same exposure,
     * but truncating it makes two different producer ids collide on their prefix — and a collision
     * there drops a genuine delivery as a duplicate, silently. It is bounded by hashing instead;
     * see {@see self::boundedWebhookId()}.
     */
    private function boundedEventType(?string $value): ?string
    {
        // Characters, not bytes: varchar(255) counts characters on PostgreSQL and on MySQL
        // under utf8mb4 alike, and a byte-wise cut could also split a multi-byte character.
        return $value === null ? null : mb_substr($value, 0, 255);
    }

    private function resolvedEventType(SignatureHeaders $headers, string $rawBody, ?string $bodyType): ?string
    {
        $spec = $this->eventTypeSpec;

        if ($spec === null) {
            return $this->nonEmpty($bodyType);
        }

        if (str_starts_with($spec, 'header:')) {
            return $this->nonEmpty($headers->get(substr($spec, 7)));
        }

        if (str_starts_with($spec, 'body:')) {
            $value = data_get($this->decodeBody($rawBody, $headers), substr($spec, 5));

            return match (true) {
                is_string($value) => $this->nonEmpty($value),
                is_int($value) => (string) $value,
                default => null,
            };
        }

        $resolver = app()->make($spec);

        return $resolver instanceof EventTypeResolver
            ? $this->nonEmpty($resolver->resolve($this->decodeBody($rawBody, $headers), $rawBody, $headers))
            : throw new InvalidArgumentException("The 'event_type' resolver for webhook client config [{$this->name}] did not resolve to an EventTypeResolver.");
    }

    /**
     * The handler job for an event type: the single configured job, the map entry
     * for this type, its '*' fallback, or the base job when nothing is configured.
     *
     * @return class-string<ProcessWebhookJob>|null
     */
    public function processJobFor(?string $type): ?string
    {
        if ($this->process === null) {
            return ProcessWebhookJob::class;
        }

        if (is_string($this->process)) {
            return $this->process;
        }

        return $this->process[$type ?? '*'] ?? $this->process['*'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function redact(): array
    {
        return $this->redact;
    }

    /**
     * @return '*'|list<string>
     */
    public function storeHeaders(): string|array
    {
        return $this->storeHeaders;
    }

    /**
     * The idempotency driver: 'redis+db' runs the cache fast path in front of the
     * partial-unique store, 'db' skips the fast path and relies on the store alone.
     */
    public function dedupe(): string
    {
        return $this->dedupe;
    }

    public function usesFastPathDedupe(): bool
    {
        return str_contains($this->dedupe, 'redis');
    }

    /**
     * Whether over-threshold bodies are written to a Storage disk instead of the
     * payload column (off by default).
     */
    public function largePayloadEnabled(): bool
    {
        return $this->largePayload['enabled'];
    }

    /**
     * The byte length above which a body is offloaded to disk.
     */
    public function largePayloadThreshold(): int
    {
        return $this->largePayload['threshold'];
    }

    /**
     * The Storage disk that holds offloaded bodies.
     */
    public function largePayloadDisk(): string
    {
        return $this->largePayload['disk'];
    }

    /**
     * The per-source inbound rate limit, or null when the source is unthrottled.
     *
     * @return array{max_attempts: int, decay_seconds: int}|null
     */
    public function rateLimit(): ?array
    {
        return $this->rateLimit;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private static function fromEntry(string $name, array $entry): self
    {
        $jwks = self::resolveJwks($name, $entry['jwks'] ?? null);
        $verifier = self::resolveVerifier($name, $entry['verifier'] ?? null);

        $secret = $entry['secret'] ?? null;

        // A static secret is required unless a JWKS url supplies the public keys, or a
        // custom verifier authenticates by other means (an API callback, a cert chain)
        // and needs no shared secret at all.
        //
        // It carries the entry's invalid_status so the HTTP boundary can answer the same
        // refusal a rejected signature gets. Read here rather than from the built config,
        // because there is no built config to read it from — this is the one branch that
        // never reaches the constructor.
        if ($jwks === null && $verifier === null && (! is_string($secret) || $secret === '')) {
            throw WebhookConfigCannotVerify::for($name, self::intOr($entry['invalid_status'] ?? null, 401));
        }

        $scheme = self::resolveScheme($name, $entry['scheme'] ?? StandardWebhooksScheme::class);

        // Non-empty is not the same as usable, and the branch above only knows the first. Standard
        // Webhooks derives its key by base64-decoding the secret, so a value with no base64
        // characters in it — the bare prefix `whsec_` above all, which is what an unset
        // `WEBHOOKS_..._SECRET=whsec_${SECRET}` expands to — passes the string test and then
        // derives zero bytes. HMAC under an empty key is a pure function of bytes the sender
        // already published, so this config would verify every forged delivery while looking
        // exactly like a configured one. Refused here, where preflight can see it, rather than at
        // the first forged request, where nothing would say anything at all.
        if ($jwks === null && $verifier === null && is_string($secret)
            && is_a($scheme, StandardWebhooksScheme::class, true)
            && ! StandardWebhooksScheme::derivesUsableKey($secret)) {
            throw WebhookConfigCannotVerify::derivesNoKey($name, self::intOr($entry['invalid_status'] ?? null, 401));
        }

        $previous = $entry['previous_secret'] ?? null;

        $headers = is_array($entry['signature_headers'] ?? null) ? $entry['signature_headers'] : [];

        return new self(
            name: $name,
            secret: is_string($secret) ? $secret : '',
            previousSecret: is_string($previous) && $previous !== '' ? $previous : null,
            schemeClass: $scheme,
            verifierClass: $verifier,
            idHeader: self::headerName($headers, 'id', StandardWebhooksScheme::HEADER_ID),
            timestampHeader: self::headerName($headers, 'timestamp', StandardWebhooksScheme::HEADER_TIMESTAMP),
            signatureHeader: self::headerName($headers, 'signature', StandardWebhooksScheme::HEADER_SIGNATURE),
            explicitHeaders: self::explicitHeaders($headers),
            tolerance: self::intOr($entry['tolerance_seconds'] ?? null, 300),
            invalidStatus: self::intOr($entry['invalid_status'] ?? null, 401),
            // Null, not a default status: null is what carries "the host did not ask for
            // the distinction", which is the only value that can be told apart from a host
            // deliberately configuring the same status invalid_status already uses.
            undeterminedStatus: is_int($entry['undetermined_status'] ?? null) ? $entry['undetermined_status'] : null,
            profileClass: self::classOr($name, 'profile', $entry['profile'] ?? null, WebhookProfile::class, ProcessEverythingWebhookProfile::class),
            responseClass: self::classOr($name, 'response', $entry['response'] ?? null, RespondsToWebhook::class, DefaultRespondsTo::class),
            modelClass: self::classOr($name, 'model', $entry['model'] ?? null, WebhookCall::class, WebhookCall::class),
            process: self::resolveProcess($name, $entry['process'] ?? null),
            redact: self::stringList($entry['redact'] ?? null, ['Authorization', 'Cookie']),
            storeHeaders: self::resolveStoreHeaders($entry['store_headers'] ?? null),
            dedupe: self::resolveDedupe($name, $entry['dedupe'] ?? null),
            dedupeId: self::resolveDedupeId($name, $entry['dedupe_id'] ?? null),
            eventTypeSpec: self::resolveEventType($name, $entry['event_type'] ?? null),
            jwksUrl: $jwks['url'] ?? null,
            jwksCacheTtl: $jwks['cacheTtl'] ?? 3600,
            jwksKid: $jwks['kid'] ?? null,
            largePayload: self::resolveLargePayload($entry['large_payload'] ?? null),
            rateLimit: self::resolveRateLimit($entry['rate_limit'] ?? null),
        );
    }

    /**
     * Normalize the optional 'large_payload' block into enabled + threshold + disk,
     * falling back to the documented defaults (off, 256 KiB, the 's3' disk).
     *
     * @return array{enabled: bool, threshold: int, disk: string}
     */
    private static function resolveLargePayload(mixed $largePayload): array
    {
        $block = is_array($largePayload) ? $largePayload : [];

        return [
            'enabled' => (bool) ($block['enabled'] ?? false),
            'threshold' => self::intOr($block['threshold'] ?? null, 262144),
            'disk' => self::stringOr($block['disk'] ?? null, 's3'),
        ];
    }

    /**
     * Normalize the optional 'rate_limit' block into a token-bucket size + decay, or
     * null when the source declares none (unthrottled).
     *
     * @return array{max_attempts: int, decay_seconds: int}|null
     */
    private static function resolveRateLimit(mixed $rateLimit): ?array
    {
        if (! is_array($rateLimit)) {
            return null;
        }

        return [
            'max_attempts' => self::intOr($rateLimit['max_attempts'] ?? null, 60),
            'decay_seconds' => self::intOr($rateLimit['decay_seconds'] ?? null, 60),
        ];
    }

    /**
     * Normalize the optional 'jwks' block into a url + cache TTL + optional kid, or
     * null when no JWKS is configured (the static-secret path).
     *
     * @return array{url: string, cacheTtl: int, kid: ?string}|null
     */
    private static function resolveJwks(string $name, mixed $jwks): ?array
    {
        if ($jwks === null) {
            return null;
        }

        if (! is_array($jwks)) {
            throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'jwks'; expected ['url' => ..., 'cache_ttl' => 3600].");
        }

        $url = $jwks['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException("The webhook client config [{$name}] 'jwks' requires a non-empty 'url'.");
        }

        $kid = $jwks['kid'] ?? null;

        return [
            'url' => $url,
            'cacheTtl' => self::intOr($jwks['cache_ttl'] ?? null, 3600),
            'kid' => is_string($kid) && $kid !== '' ? $kid : null,
        ];
    }

    /**
     * @return class-string<SignatureScheme>
     */
    private static function resolveScheme(string $name, mixed $scheme): string
    {
        if ($scheme === 'auto') {
            return StandardWebhooksScheme::class;
        }

        if (is_string($scheme) && is_a($scheme, SignatureScheme::class, true)) {
            return $scheme;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'scheme'; expected 'auto' or a SignatureScheme class-string.");
    }

    /**
     * @return class-string<InboundVerifier>|null
     */
    private static function resolveVerifier(string $name, mixed $verifier): ?string
    {
        if ($verifier === null) {
            return null;
        }

        if (is_string($verifier) && is_a($verifier, InboundVerifier::class, true)) {
            return $verifier;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'verifier'; expected an InboundVerifier class-string.");
    }

    /**
     * The idempotency DRIVER, validated at load. Only 'redis+db' (default) and 'db' are known;
     * a typo would otherwise pass silently, and because usesFastPathDedupe() keys on a 'redis'
     * substring it would quietly disable the cache fast path — a silent performance regression
     * under a retry storm, with no error. Fails loudly here like the other config keys do.
     */
    private static function resolveDedupe(string $name, mixed $dedupe): string
    {
        if ($dedupe === null) {
            return 'redis+db';
        }

        if (is_string($dedupe) && in_array($dedupe, ['redis+db', 'db'], true)) {
            return $dedupe;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'dedupe'; expected 'redis+db' or 'db'.");
    }

    /**
     * Validate the optional 'dedupe_id' strategy up front, so a typo fails at config load
     * rather than by silently keying dedupe on nothing. Returns the spec verbatim, or null
     * for the default (the id header).
     */
    private static function resolveDedupeId(string $name, mixed $spec): ?string
    {
        if ($spec === null) {
            return null;
        }

        if (is_string($spec) && (str_starts_with($spec, 'header:') || str_starts_with($spec, 'body:'))) {
            if ($spec === 'header:' || $spec === 'body:') {
                throw new InvalidArgumentException("The webhook client config [{$name}] 'dedupe_id' must name a header or body path after the prefix, e.g. 'header:X-Id' or 'body:data.id'.");
            }

            return $spec;
        }

        if (is_string($spec) && is_a($spec, DedupeKeyResolver::class, true)) {
            return $spec;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'dedupe_id'; expected 'header:Name', 'body:dotted.path', or a DedupeKeyResolver class-string.");
    }

    /**
     * Validate the optional 'event_type' strategy up front, so a typo fails at config load
     * rather than by silently logging every delivery with an empty type — a failure with no
     * red anywhere, discovered when somebody tries to split the stream. Returns the spec
     * verbatim, or null for the default (the body's own `type`).
     */
    private static function resolveEventType(string $name, mixed $spec): ?string
    {
        if ($spec === null) {
            return null;
        }

        if (is_string($spec) && (str_starts_with($spec, 'header:') || str_starts_with($spec, 'body:'))) {
            if ($spec === 'header:' || $spec === 'body:') {
                throw new InvalidArgumentException("The webhook client config [{$name}] 'event_type' must name a header or body path after the prefix, e.g. 'header:X-GitHub-Event' or 'body:eventType'.");
            }

            return $spec;
        }

        if (is_string($spec) && is_a($spec, EventTypeResolver::class, true)) {
            return $spec;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'event_type'; expected 'header:Name', 'body:dotted.path', or an EventTypeResolver class-string.");
    }

    private function nonEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * The body decoded to an array (empty when nothing could read it), for the 'body:' and
     * resolver dedupe strategies.
     *
     * Shared with the envelope rather than restated, and for a sharper reason than tidiness:
     * this runs BEFORE the envelope is built, on the same bytes. Two decoders that disagree
     * would give one delivery a payload and no idempotency key — and a null key collides with
     * nothing in the partial-unique index, so every retry of it would store a fresh row.
     *
     * @return array<array-key, mixed>
     */
    private function decodeBody(string $rawBody, SignatureHeaders $headers): array
    {
        return BodyDecoder::decode($rawBody, $headers->get('content-type'))[1];
    }

    /**
     * @template TObject of object
     *
     * @param  class-string<TObject>  $interface
     * @param  class-string<TObject>  $default
     * @return class-string<TObject>
     */
    private static function classOr(string $name, string $key, mixed $value, string $interface, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value) && is_a($value, $interface, true)) {
            return $value;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid '{$key}'.");
    }

    /**
     * @return class-string<ProcessWebhookJob>|array<string, class-string<ProcessWebhookJob>>|null
     */
    private static function resolveProcess(string $name, mixed $process): string|array|null
    {
        if ($process === null) {
            return null;
        }

        if (is_string($process)) {
            if (is_a($process, ProcessWebhookJob::class, true)) {
                return $process;
            }

            throw new InvalidArgumentException("The webhook client config [{$name}] 'process' must be a ProcessWebhookJob class-string.");
        }

        if (is_array($process)) {
            $map = [];

            foreach ($process as $type => $handler) {
                if (! is_string($type) || ! is_string($handler) || ! is_a($handler, ProcessWebhookJob::class, true)) {
                    throw new InvalidArgumentException("The webhook client config [{$name}] 'process' map must be ['event.type' => ProcessWebhookJob class-string].");
                }

                $map[$type] = $handler;
            }

            return $map;
        }

        throw new InvalidArgumentException("The webhook client config [{$name}] has an invalid 'process'.");
    }

    /**
     * @param  array<array-key, mixed>  $headers
     */
    private static function headerName(array $headers, string $key, string $default): string
    {
        return self::stringOr($headers[$key] ?? null, $default);
    }

    /**
     * The header names the host EXPLICITLY set under 'signature_headers' — only these are
     * pushed onto a scheme with its own default, so an absent key never overwrites it.
     *
     * @param  array<array-key, mixed>  $headers
     * @return array{id?: string, timestamp?: string, signature?: string}
     */
    private static function explicitHeaders(array $headers): array
    {
        $explicit = [];

        foreach (['id', 'timestamp', 'signature'] as $key) {
            $value = $headers[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $explicit[$key] = $value;
            }
        }

        return $explicit;
    }

    private static function stringOr(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringList(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return '*'|list<string>
     */
    private static function resolveStoreHeaders(mixed $value): string|array
    {
        if ($value === '*') {
            return '*';
        }

        if (is_array($value)) {
            return array_values(array_filter($value, is_string(...)));
        }

        return [];
    }
}
