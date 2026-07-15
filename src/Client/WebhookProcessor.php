<?php

declare(strict_types=1);

namespace Webhooks\Client;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Webhooks\Client\Events\InvalidWebhookSignature;
use Webhooks\Client\Http\CaptureRawBody;
use Webhooks\Client\Models\WebhookCall;
use Webhooks\Client\Verification\InboundVerifier;
use Webhooks\Core\Payload\PayloadSanitizer;
use Webhooks\Core\Payload\PayloadStore;
use Webhooks\Core\Signing\SignatureHeaders;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\Dialect\Sql\DedupeInsert;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

/**
 * Runs the whole receiving pipeline for one request, controller-less: capture the
 * raw bytes, verify the signature, reject replays, de-duplicate, filter, store and
 * dispatch. Drive it from the Route::webhooks() macro or directly:
 *
 *     new WebhookProcessor($request, WebhookConfig::forName('stripe'))->process();
 *
 * A bad, expired or malformed signature aborts with the config's invalid_status
 * (401 by default) — never a 500, because such a request can never be made valid by
 * a retry. A duplicate is answered with the same success response but not re-stored
 * or re-dispatched.
 */
final readonly class WebhookProcessor
{
    /**
     * The seen-key lives a little longer than the tolerance window so a burst of
     * retries is absorbed by the fast path without ever reaching the database.
     */
    private const int SEEN_TTL_BUFFER = 300;

    public function __construct(
        private Request $request,
        private WebhookConfig $config,
    ) {}

    private function db(): ConnectionInterface
    {
        return WebhookConnection::db();
    }

    public function process(): Response
    {
        $rawBody = $this->rawBody();
        $headers = SignatureHeaders::from($this->flattenHeaders());

        // A configured verifier authenticates the request itself (an API callback, a cert
        // chain) and takes precedence over the signature scheme; only its absence falls
        // through to the pure-function scheme + shared secret.
        $verifier = $this->config->verifier();

        $result = $verifier instanceof InboundVerifier
            ? $verifier->verify($this->request, $this->config)
            : $this->config->scheme()->verify(
                $rawBody,
                $headers,
                $this->config->secrets(),
                $this->config->tolerance(),
            );

        if (! $result->isValid()) {
            InvalidWebhookSignature::dispatch($this->request, $this->config, $result->reason());

            abort($this->config->invalidStatus());
        }

        // Throttle authentic requests per source. This runs after verification so a
        // forged request can never exhaust a real producer's bucket, and before the
        // store so a limited request is neither persisted nor dispatched.
        $this->enforceRateLimit();

        $webhookId = $this->config->webhookId($headers, $rawBody);
        $fastPathDedupe = $webhookId !== null && $this->config->usesFastPathDedupe();

        // Fast-path dedupe: a repeated delivery whose id was already durably stored
        // short-circuits to the success response before touching the database. The
        // "seen" marker is written only AFTER a successful store (below), never here,
        // so a store failure can never leave a marker that would swallow the
        // producer's retry with a bare success. The authoritative partial-unique
        // insert still guards a concurrent race.
        if ($fastPathDedupe && Cache::has($this->cacheKey($webhookId))) {
            return $this->respond();
        }

        if (! $this->config->profile()->shouldProcess($this->request)) {
            return $this->respond();
        }

        $message = InboundMessage::fromRawBody($rawBody, $webhookId);

        $call = $this->store($rawBody, $webhookId, $message);

        // The id is now durably stored — either this request inserted it, or a
        // concurrent request already did (the insert returned null) — so it is safe
        // to arm the fast path. Arming it here rather than before store() means a
        // failed store never marks the id seen, so a retry re-processes it.
        if ($fastPathDedupe) {
            Cache::put($this->cacheKey($webhookId), true, $this->config->tolerance() + self::SEEN_TTL_BUFFER);
        }

        // Authoritative dedupe: the partial-unique insert returned nothing, so a
        // concurrent request already stored this id. Do not dispatch a second time.
        if (! $call instanceof WebhookCall) {
            return $this->respond();
        }

        $this->dispatchProcessing($call, $message);

        return $this->respond();
    }

    /**
     * Insert the call as a partial-unique upsert. The ON CONFLICT target carries the
     * index predicate because the unique index is partial; a null webhook_id is not
     * covered by the index, so such a row always inserts. Returns null when a row
     * with the same (source, webhook_id) already exists.
     */
    private function store(string $rawBody, ?string $webhookId, InboundMessage $message): ?WebhookCall
    {
        $id = (string) Str::uuid7();
        $headersJson = $this->redactedHeadersJson();
        [$payload, $disk, $path] = $this->resolvePayload($rawBody, $message);

        // The payload column is jsonb, which cannot hold a NUL byte at all — an insert
        // carrying one fails outright, and inbound that means a 500 on every retry until
        // the producer gives up and a signature-verified webhook is lost. Scrub the
        // parsed view; the exact received bytes are kept verbatim beside it (raw_body +
        // body_sha256), so nothing the producer sent is destroyed.
        $payloadJson = json_encode(PayloadSanitizer::scrub($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The bytes themselves are kept beside the parsed view — base64, so any body
        // survives the column — unless they were offloaded, which already stores them
        // verbatim. That is what makes body_sha256 a promise the row can keep.
        $storedBody = $path === null ? WebhookCall::encodeRawBody($rawBody) : null;

        $dialect = WebhookConnection::dialect();
        $sql = DedupeInsert::webhookCalls($dialect);
        $bindings = [$id, $this->config->name, $webhookId, $message->type, $payloadJson, $storedBody, $disk, $path, hash('sha256', $rawBody), $headersJson];

        // PostgreSQL returns the inserted id (null on a duplicate); MySQL reports the outcome
        // through the affected-row count (1 inserted, 0 duplicate) and binds its timestamps from
        // PHP as UTC, since its ON DUPLICATE KEY form carries no now() and the session zone is
        // untrustworthy. Either way a duplicate yields null, and the row is then read by id.
        if ($dialect === Dialect::MySql) {
            $now = Timestamp::mysql(Date::now());

            if ($this->db()->affectingStatement($sql, [...$bindings, $now, $now]) === 0) {
                return null;
            }
        } elseif ($this->db()->selectOne($sql, $bindings) === null) {
            return null;
        }

        $model = $this->config->model();

        return new $model()->newQuery()->find($id);
    }

    /**
     * The payload column plus optional offload pointer. When offload is enabled and
     * the raw body clears the byte threshold, the body is written to the Storage disk
     * and only a small envelope stub (keeping payload_type/event_type queryable) is
     * kept in the column; otherwise the parsed envelope is stored inline.
     *
     * @return array{0: array<array-key, mixed>, 1: string|null, 2: string|null}
     */
    private function resolvePayload(string $rawBody, InboundMessage $message): array
    {
        if (! $this->config->largePayloadEnabled() || strlen($rawBody) <= $this->config->largePayloadThreshold()) {
            return [$message->payload, null, null];
        }

        $disk = $this->config->largePayloadDisk();
        $pointer = new PayloadStore()->offload($rawBody, $disk);

        return [$this->offloadStub($message), $disk, $pointer['path']];
    }

    /**
     * The compact stub kept in the payload column for an offloaded body: the envelope
     * type when present, so the generated payload_type column stays populated and the
     * dashboard can still group by event type without the full body.
     *
     * @return array<string, string>
     */
    private function offloadStub(InboundMessage $message): array
    {
        return $message->type === null ? [] : ['type' => $message->type];
    }

    /**
     * Apply the source's token bucket, when one is configured. On exhaustion the
     * request is answered with 429 and a Retry-After hint (the seconds until the
     * bucket refills) and nothing is stored or dispatched; a successful hit consumes
     * one token. The cache-backed limiter is atomic, so Redis makes this correct
     * across processes while the array store keeps it usable in tests.
     */
    private function enforceRateLimit(): void
    {
        $limit = $this->config->rateLimit();

        if ($limit === null) {
            return;
        }

        $key = "webhooks:inbound:{$this->config->name}";

        if (RateLimiter::tooManyAttempts($key, $limit['max_attempts'])) {
            abort(429, headers: ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, $limit['decay_seconds']);
    }

    private function dispatchProcessing(WebhookCall $call, InboundMessage $message): void
    {
        $jobClass = $this->config->processJobFor($message->type);

        if ($jobClass === null) {
            return;
        }

        dispatch(new $jobClass($call, $message));
    }

    /**
     * The redacted headers to persist, or null when store_headers is empty. Names in
     * the redact list (plus Authorization and Cookie, always) are masked; a list of
     * store_headers keeps only those names, '*' keeps them all.
     */
    private function redactedHeadersJson(): ?string
    {
        $store = $this->config->storeHeaders();

        if ($store === []) {
            return null;
        }

        $redact = array_map(strtolower(...), ['authorization', 'cookie', ...$this->config->redact()]);
        $only = is_array($store) ? array_map(strtolower(...), $store) : null;

        $stored = [];

        foreach ($this->flattenHeaders() as $name => $value) {
            $lower = strtolower($name);

            if ($only !== null && ! in_array($lower, $only, true)) {
                continue;
            }

            $stored[$name] = in_array($lower, $redact, true) ? '[redacted]' : $value;
        }

        return json_encode(PayloadSanitizer::scrub($stored), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(): array
    {
        $flat = [];

        foreach ($this->request->headers->all() as $name => $values) {
            $flat[$name] = (string) ($values[0] ?? '');
        }

        return $flat;
    }

    private function rawBody(): string
    {
        $captured = $this->request->attributes->get(CaptureRawBody::ATTRIBUTE);

        return is_string($captured) ? $captured : $this->request->getContent();
    }

    private function cacheKey(string $webhookId): string
    {
        return "webhooks:seen:{$this->config->name}:{$webhookId}";
    }

    private function respond(): Response
    {
        return $this->config->response()->respond($this->request);
    }
}
