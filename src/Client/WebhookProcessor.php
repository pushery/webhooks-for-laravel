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
use Pushery\Webhooks\Core\Signing\VerificationResult;
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

        // Resolving the key material is its own step, because for a `jwks` source it is an
        // outbound HTTP call and it can fail. It used to sit inline as an argument to verify(),
        // so a provider that was down, serving a maintenance page or simply unreachable threw
        // straight past the controller -- which catches only WebhookConfigCannotVerify -- and
        // every anonymous POST was answered 500.
        //
        // That is the one answer a producer reads as "try again", on the path three docblocks
        // promise is never a 500, and it made the outage worse in both directions: an empty
        // fetch is deliberately not cached (so a blip is not an hour-long outage), which means
        // each request retried the fetch, and an anonymous caller could amplify against the
        // provider in this installation's name.
        //
        // A key set that could not be resolved is exactly what "undetermined" already means
        // here: the verification did not complete, and a retry could resolve it. The host still
        // decides what that answers with -- undetermined_status falls back to invalid_status, so
        // an installation that has not opted into a distinguishable answer sees no change.
        $keyLookupFailed = false;

        if (! $verifier instanceof InboundVerifier) {
            try {
                $secrets = $this->config->secrets();
            } catch (Throwable $keyLookupFailure) {
                $keyLookupFailed = true;

                // Reported, not swallowed: a provider whose JWKS stopped resolving is a real
                // operational fault, and answering 401 without saying so anywhere would turn a
                // broken integration into silence. Guarded for the same reason as every other
                // report on this path -- reporting can itself throw, and it must not become the
                // 500 this whole change removes.
                try {
                    report($keyLookupFailure);
                } catch (Throwable) {
                    // Nothing above this can report, and the refusal below still answers.
                }
            }
        }

        $result = match (true) {
            $verifier instanceof InboundVerifier => $verifier->verify($this->request, $this->config),
            $keyLookupFailed => VerificationResult::undetermined(),
            default => $this->config->scheme()->verify(
                $rawBody,
                $headers,
                $secrets ?? $this->config->secrets(),
                $this->config->tolerance(),
            ),
        };

        if (! $result->isValid()) {
            // Guarded like the two announcements below, and this is the one with a recorded
            // incident behind it: the event's own docblock describes a throw landing INSIDE
            // dispatch(), before the abort() on the next line, turning every forged, unsigned
            // or expired POST into a 500 from an anonymous caller — on the one path three
            // docblocks promise is never a 500. That cause was fixed by taking the Request out
            // of the payload; the SHAPE that let a listener reach the abort() was not, and this
            // event's docblock invites exactly the listener most likely to throw ("so a
            // listener can alert or rate-limit an abusive source" — an abuse listener is the
            // kind that reaches for Gate::authorize() or abort()).
            //
            // The asymmetry with the siblings also ran the wrong way: there, a guard protects a
            // delivery that is already stored. Here nothing is stored, but the caller is
            // unauthenticated and the answer is the one thing a producer acts on — 5xx is read
            // as "try again" for a request that can never become valid.
            try {
                InvalidWebhookSignature::dispatch(
                    $this->config->name,
                    $result->reason(),
                    $this->request->ip(),
                    $this->request->path(),
                    $this->request->userAgent(),
                );
            } catch (Throwable $listenerFailure) {
                try {
                    report(InboundListenerFailed::for('invalid-signature', $this->config->name, $listenerFailure));
                } catch (Throwable) {
                    // Nothing above this can report, and the refusal below still answers.
                }
            }

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

        // Throttle authentic requests per source. The refusal runs after verification, so a
        // forged request can never exhaust a real producer's bucket, and before the store, so a
        // limited request is neither persisted nor dispatched. What SPENDS a token is separate
        // and happens further down, once this delivery is known not to be a repeat of one
        // already counted — see countAgainstRateLimit().
        $this->refuseWhenRateLimited();

        $webhookId = $this->config->webhookId($headers, $rawBody);
        $fastPathDedupe = $webhookId !== null && $this->config->usesFastPathDedupe();

        // Fast-path dedupe: a repeated delivery whose id was already stored AND queued
        // short-circuits to the success response before touching the database. The "seen"
        // marker is armed after both the store and the dispatch succeed, and — for a repeat the
        // DATABASE recognized — by the duplicate branch below. Neither a store nor a dispatch
        // failure may leave a marker behind that would swallow the producer's retry with a bare
        // success, which is why the rollback clears it as well as deleting the row. The
        // authoritative partial-unique insert still guards a concurrent race.
        if ($fastPathDedupe && $this->alreadySeen($webhookId)) {
            return $this->respond();
        }

        if (! $this->config->profile()->shouldProcess($this->request)) {
            // Counted. The host chose to ignore this delivery, but the producer still sent a
            // distinct one, and a filtered delivery that cost nothing would be an unlimited
            // channel through the very check above.
            $this->countAgainstRateLimit();

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

        // Authoritative dedupe: the partial-unique insert returned nothing, so a concurrent
        // request already stored this id. Arm the fast path for the next retry and do not
        // dispatch a second time — a further repeat then costs a cache hit instead of another
        // parse, offload and insert, which is the retry storm this path exists to absorb.
        //
        // This branch vouches for a row somebody else may still roll back, which is why the
        // rollback below now clears the marker as well. The marker means "stored and queued"
        // everywhere else; here it can only mean "stored by someone", because whether that someone
        // went on to queue anything is a fact this request does not have. Measured before the
        // rollback cleared it:
        //
        //   A stores its row and has not dispatched yet
        //   B lands here, arms the marker, answers 200
        //   A's dispatch throws -> A deletes its row, leaving the id "UNSEEN" per its comment
        //   the producer retries -> markedSeenByDuplicate=true, retryStatus=200, rowsAfterRetry=0
        //
        // A verified delivery, acknowledged with a bare success, stored nowhere and handled by
        // nobody — the outcome that delete exists to prevent, defeated from three lines above it.
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

            // And the marker, which this request may not have set. A concurrent duplicate can have
            // armed it against the row being deleted right here — it saw the row, it could not see
            // that this dispatch would fail. Deleting the row without clearing the marker leaves
            // the id looking handled and stored nowhere, which is the one state this rollback
            // exists to rule out. Clearing a marker this request did not set is safe in the other
            // direction too: the worst it costs a genuine repeat is one more trip through the
            // authoritative insert.
            $this->forgetSeen($fastPathDedupe, $webhookId);

            throw $e;
        }

        // Stored AND queued: only now arm the fast path, so a store that succeeded but
        // whose dispatch failed never marks the id seen. A producer retry within the
        // window then short-circuits to the success response without another database
        // round-trip.
        $this->markSeen($fastPathDedupe, $webhookId);

        // And spend the token here rather than at the check, for the same reason: this is the
        // point at which the delivery is known to be new AND to have cost a row and a job. A
        // dispatch that threw took the row with it above and never reaches this line, so a queue
        // outage does not drain the producer's bucket on top of everything else.
        $this->countAgainstRateLimit();

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
     * Whether the fast path has this id marked, and false when the cache cannot say.
     *
     * The cache is an accelerator in front of the partial-unique index, not an authority: its
     * whole job is to absorb a retry storm before it reaches the database. Read unguarded, an
     * unreachable store turned that into a hard dependency -- with Redis down the receiver
     * accepted NOTHING, answering 500 to every delivery, while the index that actually
     * guarantees uniqueness was working the entire time.
     *
     * Falling through to the insert is the honest degradation: it costs a database round trip
     * per retry, which is exactly what the cache exists to save and not something anyone is
     * owed. It cannot produce a duplicate, because the index is what refuses one.
     *
     * Not reported, and that is a decision rather than an oversight: a cache outage means EVERY
     * delivery takes this path, so reporting here would turn one incident into a second one made
     * of log volume. The store's own health is the host's monitoring, not this request's.
     */
    private function alreadySeen(string $webhookId): bool
    {
        try {
            return Cache::has($this->cacheKey($webhookId));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Arm the fast-path "seen" marker for a durably-stored id, held until the replay
     * tolerance elapses (plus a buffer). A no-op when the source has no fast-path
     * dedupe or the delivery carried no id.
     */
    private function markSeen(bool $fastPathDedupe, ?string $webhookId): void
    {
        if ($fastPathDedupe && $webhookId !== null) {
            try {
                Cache::put($this->cacheKey($webhookId), true, $this->config->tolerance() + self::SEEN_TTL_BUFFER);
            } catch (Throwable) {
                // The row is stored and the job is queued; the marker is an optimization for the
                // next retry. Losing it costs that retry one database round trip, and raising
                // here would answer 500 for a delivery that fully succeeded.
            }
        }
    }

    /**
     * Withdraw the "seen" marker for an id this request is rolling back.
     *
     * The mirror of {@see self::markSeen()}, and it exists because the marker is not private to
     * the request that set it: a concurrent duplicate arms it against a row that only later turns
     * out to be doomed. Symmetry with the delete is the whole property — the row and the marker
     * are two halves of "this delivery is handled", and half of that surviving a rollback is
     * exactly the state a retry gets swallowed by.
     */
    private function forgetSeen(bool $fastPathDedupe, ?string $webhookId): void
    {
        if ($fastPathDedupe && $webhookId !== null) {
            try {
                Cache::forget($this->cacheKey($webhookId));
            } catch (Throwable) {
                // This runs inside the rollback that then re-raises, so an exception here would
                // REPLACE the failure being reported with a cache error -- hiding the reason the
                // rollback happened at all. And a store that cannot forget could not have been
                // marked either, so there is nothing left behind to withdraw.
            }
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
            // The Postgres arm is a write that Laravel's select path cannot recognize as one, and
            // it needs both lines below rather than either.
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
     * The compact stub kept in the payload column for an offloaded body: the envelope's OWN
     * type when it had one, so the generated payload_type column reads the same for an
     * offloaded row as it would have for an inline one.
     *
     * It writes the body's type rather than the resolved one, and the difference is a whole
     * column's trustworthiness. `payload_type` is `payload->>'type'` — the docs call it "a stored
     * generated column mirroring the payload's own type field" — and this stub used to write
     * `$message->type`, which by this point carries whatever `event_type` resolved to, including a
     * value read from a header the body never had.
     *
     * Measured on a header-typed source with offload on:
     *
     *   large  offloaded=true   event_type=thing.happened  payload_type=thing.happened
     *   small  offloaded=false  event_type=thing.happened  payload_type=NULL
     *
     * So the column was populated for exactly the rows that cleared the size threshold. A query
     * filtering on it silently returned a size-biased subset — not an error, not an empty
     * result, just the large deliveries — and the bias grows with the threshold, which is the
     * one knob an operator turns without expecting it to change what queries mean.
     *
     * Dropping the stub's type entirely would have inverted the asymmetry rather than removed
     * it: for the ordinary body-typed producer, the offloaded rows would then be the NULL ones.
     * Reading the payload's own type makes both halves agree in both cases, which is what the
     * column's definition already claimed.
     *
     * @return array<string, string>
     */
    private function offloadStub(InboundMessage $message): array
    {
        $bodyType = $message->payload['type'] ?? null;

        // Two statements rather than a ternary, for the coverage reason ConstantFallbackVisibility
        // holds across this package: pcov credits a one-line expression to every line it spans, so
        // a ternary reads as covered the first time EITHER arm runs — and the arm that goes
        // unexercised here is the one deciding whether payload_type stays empty.
        if (is_string($bodyType) && $bodyType !== '') {
            return ['type' => $bodyType];
        }

        return [];
    }

    /**
     * Apply the source's token bucket, when one is configured. On exhaustion the
     * request is answered with 429 and a Retry-After hint (the seconds until the
     * bucket refills) and nothing is stored or dispatched; a successful hit consumes
     * one token. The cache-backed limiter is atomic, so Redis makes this correct
     * across processes while the array store keeps it usable in tests.
     */
    private function refuseWhenRateLimited(): void
    {
        $limit = $this->config->rateLimit();

        if ($limit === null) {
            return;
        }

        $key = $this->rateLimitKey();

        if (RateLimiter::tooManyAttempts($key, $limit['max_attempts'])) {
            // The cast changes no outcome — PHP coerces the int into the header value either way.
            // It stays because the header array is declared as strings and the cast is where that
            // becomes true, not because a test needs it.
            abort(429, headers: ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
    }

    /**
     * Spend one token, for a delivery that was not a repeat of one already taken.
     *
     * Separated from the check above, and that separation is the fix. The two used to be one
     * call placed before the dedupe, so a REPLAY spent a token: a captured authentic delivery
     * verifies correctly, and replaying it enough times emptied the source's bucket and left the
     * real producer answering 429 until the window rolled. The comment above the old call said
     * the ordering meant "a forged request can never exhaust a real producer's bucket", which was
     * true and was not the whole set — a replay is not forged.
     *
     * The bucket is per SOURCE rather than per sender, so there is no address to charge instead;
     * what has to change is which requests count. A delivery the fast path or the authoritative
     * insert recognized as one already taken does not, because it caused no work: no row, no
     * offload, no job. Everything else does, including a delivery the host's own profile filters
     * out — that is a distinct delivery the producer chose to send, and not counting it would
     * hand back the unlimited channel this check exists to close.
     *
     * The check and the count are no longer atomic, so two requests can pass the check before
     * either counts. The window is one request handler wide and the effect is bounded by the
     * number of concurrent requests; the alternative is charging for repeats, which is the
     * defect.
     */
    private function countAgainstRateLimit(): void
    {
        $limit = $this->config->rateLimit();

        if ($limit === null) {
            return;
        }

        RateLimiter::hit($this->rateLimitKey(), $limit['decay_seconds']);
    }

    private function rateLimitKey(): string
    {
        return "webhooks:inbound:{$this->config->name}";
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
     * the redact list (plus everything in HeaderRedactor::ALWAYS) are masked; a list of
     * store_headers keeps only those names, '*' keeps them all.
     */
    private function redactedHeadersJson(): ?string
    {
        $store = $this->config->storeHeaders();

        if ($store === []) {
            return null;
        }

        // The CONFIG side is lowered here and is load-bearing: a host writes 'X-Keep', and
        // without this the comparison below never matches. Measured — removing it goes red.
        $only = is_array($store) ? array_map(strtolower(...), $store) : null;

        $kept = [];

        foreach ($this->flattenHeaders() as $name => $value) {
            // The strtolower on the name cannot change anything: Symfony's HeaderBag::all() already
            // returns every key lowercased, even for a header set as 'X-MiXeD-CaSe', so no request
            // can produce a name it would alter. Its twin on the config side, six lines up, is not
            // in that position and goes red when removed; that asymmetry is what makes this a claim
            // about this call rather than about an untested filter.
            //
            // It is kept because it says the comparison is case-insensitive at the point where a
            // reader asks, instead of making them go and check what HeaderBag guarantees.
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
