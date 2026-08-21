<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Pushery\Webhooks\Client\Events\InboundWebhookVerified;
use Pushery\Webhooks\Client\Events\InvalidWebhookSignature;
use Pushery\Webhooks\Client\Events\UnreadableWebhookPayload;
use Pushery\Webhooks\Client\Exceptions\InboundListenerFailed;
use Pushery\Webhooks\Client\Http\RawBody;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Client\Verification\InboundVerifier;
use Pushery\Webhooks\Core\Http\HeaderRedactor;
use Pushery\Webhooks\Core\Payload\PayloadSanitizer;
use Pushery\Webhooks\Core\Payload\PayloadStore;
use Pushery\Webhooks\Core\Signing\SignatureHeaders;
use Pushery\Webhooks\Core\Signing\VerificationStatus;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Database\Dialect\Sql\DedupeInsert;
use Pushery\Webhooks\Search\SearchIndexer;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs the whole receiving pipeline for one request, controller-less: capture the
 * raw bytes, verify the signature, reject replays, de-duplicate, filter, store and
 * dispatch. Drive it from the Route::webhooks() macro or directly:
 *
 *     new WebhookProcessor($request, WebhookConfig::forName('stripe'))->process();
 *
 * A bad, expired or malformed signature aborts with the config's invalid_status
 * (401 by default) — never a 500, because such a request can never be made valid by
 * a retry. A verification that did not COMPLETE — an InboundVerifier whose provider
 * callback timed out — is the exception: it is refused just as hard, but it is the one
 * refusal a retry could resolve, so it can be answered separately through
 * undetermined_status. A duplicate is answered with the same success response but not
 * re-stored or re-dispatched.
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
            InvalidWebhookSignature::dispatch(
                $this->config->name,
                $result->reason(),
                $this->request->ip(),
                $this->request->path(),
                $this->request->userAgent(),
            );

            // Every refusal stores nothing and dispatches nothing. What can differ is the
            // one thing the SENDER can act on: whether to try again. A verification that did
            // not complete — the provider callback timed out — is the only refusal a retry
            // could resolve, and answering it 401 tells a producer to give up on a delivery
            // that was probably genuine. It still needs the host's consent, because a
            // distinguishable answer is information a prober can also read, so an unset
            // undetermined_status falls back to invalid_status and nothing changes.
            abort($result->status === VerificationStatus::Undetermined
                ? $this->config->undeterminedStatus()
                : $this->config->invalidStatus());
        }

        // The delivery is authentic from here on, and which secret proved it is the one thing
        // about a rotation nobody could otherwise see. Guarded like the unreadable-payload
        // announcement below and for the same reason: the delivery is genuine and already
        // verified, so a broken ledger must not answer the producer 500 and ask it to retry
        // something that succeeded.
        try {
            InboundWebhookVerified::dispatch($this->config->name, $result->matchedKeyId);
        } catch (Throwable $listenerFailure) {
            try {
                report(InboundListenerFailed::for('verified', $this->config->name, $listenerFailure));
            } catch (Throwable) {
                // Nothing above this can report, and the delivery is untouched.
            }
        }

        // Throttle authentic requests per source. This runs after verification so a
        // forged request can never exhaust a real producer's bucket, and before the
        // store so a limited request is neither persisted nor dispatched.
        $this->enforceRateLimit();

        $webhookId = $this->config->webhookId($headers, $rawBody);
        $fastPathDedupe = $webhookId !== null && $this->config->usesFastPathDedupe();

        // Fast-path dedupe: a repeated delivery whose id was already stored AND queued
        // short-circuits to the success response before touching the database. The
        // "seen" marker is armed only after both the store and the dispatch succeed
        // (below), never here, so neither a store nor a dispatch failure can leave a
        // marker that would swallow the producer's retry with a bare success. The
        // authoritative partial-unique insert still guards a concurrent race.
        if ($fastPathDedupe && Cache::has($this->cacheKey($webhookId))) {
            return $this->respond();
        }

        if (! $this->config->profile()->shouldProcess($this->request)) {
            return $this->respond();
        }

        $message = InboundMessage::fromRawBody($rawBody, $webhookId, $headers->get('content-type'));

        // The event type is the column a stream is split on and what picks the handler job,
        // and not every producer puts it in the body: GitHub sends `X-GitHub-Event` and
        // leaves the body carrying only `action`. Read from the body alone, such an
        // installation logs every delivery with an EMPTY type -- nothing goes red, the
        // stream simply cannot be split. So the config gets to say where it comes from,
        // with the body's own value as the default it hands back unchanged.
        $eventType = $this->config->eventTypeFor($headers, $rawBody, $message->type);

        if ($eventType !== $message->type) {
            $message = new InboundMessage(
                id: $message->id,
                type: $eventType,
                createdAt: $message->createdAt,
                data: $message->data,
                payload: $message->payload,
                format: $message->format,
            );
        }

        $call = $this->store($rawBody, $webhookId, $message);

        // Authoritative dedupe: the partial-unique insert returned nothing, so a
        // concurrent request already stored this id. Arm the fast path for the next
        // retry and do not dispatch a second time.
        if (! $call instanceof WebhookCall) {
            $this->markSeen($fastPathDedupe, $webhookId);

            return $this->respond();
        }

        // Dispatch the processing job. This is the one step left that can still fail
        // after the durable insert (a queue-backend blip, a serialization error), and
        // until it succeeds the call has no worker. If it throws, delete the row this
        // request just inserted and leave the id UNSEEN, so the producer's retry
        // re-stores and re-dispatches instead of being swallowed as a duplicate and
        // acknowledged with a bare success — the delivery is never silently lost.
        try {
            $this->dispatchProcessing($call, $message);
        } catch (Throwable $e) {
            $call->delete();

            throw $e;
        }

        // Stored AND queued: only now arm the fast path, so a store that succeeded but
        // whose dispatch failed never marks the id seen. A producer retry within the
        // window then short-circuits to the success response without another database
        // round-trip.
        $this->markSeen($fastPathDedupe, $webhookId);

        // A body nothing could read is the one failure this pipeline cannot answer for the
        // host: the signature verified, so the delivery is authentic and has just been stored
        // and queued — but its meaning is still sitting unread in the bytes. Say so once, here,
        // so a listener can alert instead of the silence a handler that finds no fields would
        // otherwise produce.
        //
        // Announced only after the row and the job are durable, deliberately. A listener is
        // host code and may throw or be queued; announced any earlier, one that did would take
        // the delivery down with it and leave nothing behind — turning a silent loss into a
        // total one, which is the opposite of what this is for.
        //
        // For the same reason its failure is reported rather than raised. Letting it escape
        // would answer a stored, queued delivery with a 500, and a body nothing could read
        // often has no dedupe key to recognize the retry by — so the retry would store another
        // row and run the handler again.
        //
        // Wrapped rather than reported as-is, because those are not the same thing: Laravel's
        // handler skips a documented set of exceptions outright, and a listener that ran
        // firstOrFail(), Gate::authorize(), validate() or abort() throws one of them. Reporting
        // it would be a silent no-op — a failed alert that is itself unreported, which is this
        // area's own failure one level up. A package-owned class is in no host's ignore list.
        //
        // And the report is guarded too. It is the last step: reporting can throw (a reportable
        // exception whose own report() fails, a reportable() callback, a logger that cannot be
        // built), and at this point there is nothing left to attempt that does not cost the
        // delivery a second row.
        if (! $message->format->readable()) {
            try {
                UnreadableWebhookPayload::dispatch($call, $this->config->name, $headers->get('content-type'));
            } catch (Throwable $listenerFailure) {
                try {
                    report(InboundListenerFailed::for('unreadable-payload', $this->config->name, $listenerFailure));
                } catch (Throwable) {
                    // Nothing above this can report, and the delivery is already safe.
                }
            }
        }

        return $this->respond();
    }

    /**
     * Arm the fast-path "seen" marker for a durably-stored id, held until the replay
     * tolerance elapses (plus a buffer). A no-op when the source has no fast-path
     * dedupe or the delivery carried no id.
     */
    private function markSeen(bool $fastPathDedupe, ?string $webhookId): void
    {
        if ($fastPathDedupe && $webhookId !== null) {
            Cache::put($this->cacheKey($webhookId), true, $this->config->tolerance() + self::SEEN_TTL_BUFFER);
        }
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
        $connection = $this->db();

        if ($dialect === Dialect::MySql) {
            $now = Timestamp::mysql(Date::now());

            if ($connection->affectingStatement($sql, [...$bindings, $now, $now]) === 0) {
                return null;
            }
        } else {
            // ⚠️ THE POSTGRES ARM IS A WRITE THAT LARAVEL'S SELECT PATH CANNOT RECOGNIZE AS ONE,
            // and it needs BOTH lines below rather than either.
            //
            // `INSERT … ON CONFLICT … RETURNING id` has to come back through `selectOne()` to
            // read the returned id, and `selectOne()` defaults `$useReadPdo` to TRUE
            // (Connection::selectOne -> select -> getPdoForSelect -> getReadPdo). On a host
            // running this connection with Laravel's documented `read`/`write` split, the third
            // argument is the difference between the insert reaching the primary and reaching a
            // replica. Against a streaming replica that is SQLSTATE 25006 and a 500 on every
            // authentic delivery; where the read node accepts writes it is worse, because the
            // row lands off the write path, the `find()` below reads a node that does not have
            // it, and this method reports the delivery as a DUPLICATE.
            //
            // `select()` also never calls `recordsHaveBeenModified()`, so `sticky` cannot
            // protect the read that follows — the flag has to be set by hand. The MySQL arm
            // above is immune to both by accident: `affectingStatement()` always takes
            // `getPdo()` and records the modification itself.
            //
            // Invisible in a transactional test by construction: inside a transaction
            // `getReadPdo()` returns the write PDO, so the whole suite masks it.
            $inserted = $connection->selectOne($sql, $bindings, false);

            if ($connection instanceof Connection) {
                $connection->recordsHaveBeenModified();
            }

            if ($inserted === null) {
                return null;
            }
        }

        $model = $this->config->model();

        $call = new $model()->newQuery()->find($id);

        // The row was written by a raw SQL upsert, which fires no Eloquent event, so Scout's
        // observer never sees it. Index it explicitly — a no-op unless search is on and the
        // configured model is a searchable one — so an external engine actually gets the call.
        SearchIndexer::indexModel($call);

        return $call;
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

        $only = is_array($store) ? array_map(strtolower(...), $store) : null;

        $kept = [];

        foreach ($this->flattenHeaders() as $name => $value) {
            if ($only !== null && ! in_array(strtolower($name), $only, true)) {
                continue;
            }

            $kept[$name] = $value;
        }

        // The always-masked set (Authorization, Cookie) plus the host's redact list, applied
        // through the shared redactor so the live path and the backfill import can never disagree.
        $stored = HeaderRedactor::mask($kept, $this->config->redact());

        // Substitute invalid UTF-8, the same lossy-but-valid guarantee scrub() gives NUL bytes.
        // Header VALUES are read verbatim off the wire (never through json_decode like the
        // payload), so a stray non-UTF-8 byte — a Latin-1 accent, an intermediary's injected
        // byte — would otherwise make this throw AFTER the signature already verified, 500 every
        // retry and silently lose the webhook. The exact bytes survive in raw_body + body_sha256.
        return json_encode(PayloadSanitizer::scrub($stored), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
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
        // Delegated rather than duplicated: RawBody is the supported way for a verifier to
        // reach these bytes, and two implementations of "the body" would eventually disagree
        // about one delivery — which is the whole class of bug this resolution exists to
        // prevent.
        return RawBody::of($this->request);
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
