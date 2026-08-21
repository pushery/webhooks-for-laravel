<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client;

use Pushery\Webhooks\Client\Http\BodyDecoder;

/**
 * The parsed envelope of an incoming webhook: the producer's id, the event type, the
 * created-at timestamp and the data payload, read from the body in whichever format the
 * producer sent it. This is the RECEIVE-side view of a message — the one a handler job
 * reads via $this->message — as distinct from `Pushery\Webhooks\Core\Signing\WebhookMessage`,
 * which models the exact bytes a signature is computed over. Every field is nullable
 * because an arbitrary producer need not follow any particular body convention.
 *
 * $format says how the body was read, and a handler that acts on the payload should
 * consult it: an empty payload with {@see PayloadFormat::Unreadable} is a delivery whose
 * meaning is still unread, not a delivery that carried nothing.
 */
final readonly class InboundMessage
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public ?string $id,
        public ?string $type,
        public ?int $createdAt,
        public array $data,
        public array $payload,
        public PayloadFormat $format = PayloadFormat::Json,
    ) {}

    /**
     * Rebuild an envelope from a serialized one, including one written before $format existed.
     *
     * A queued handler job carries this object in its payload, so an upgrade meets envelopes
     * that were serialized by an older release — a backlog, a delayed dispatch, a `queue:retry`
     * out of failed_jobs. Those carry five properties, and PHP does NOT apply a promoted
     * parameter's default when it unserializes: $format would be left uninitialized, and the
     * very first line this release asks a handler to write reads it. That is a fatal error on
     * every in-flight delivery, which is a worse failure than the one being fixed.
     *
     * Json is the honest value to fill in for that case, because it is the only thing the payload
     * of an older envelope could have come from. It is NOT the value for a member that arrives
     * mistyped, though: a payload PHP would once have rejected outright must not come back as a
     * readable envelope carrying nothing, which is the very shape $format exists to name. So a
     * missing $format means an older release wrote this, and anything else wrong with it means
     * the envelope itself is unreadable.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $rawId = $data['id'] ?? null;
        $rawType = $data['type'] ?? null;
        $rawCreatedAt = $data['createdAt'] ?? null;
        $rawData = $data['data'] ?? [];
        $rawPayload = $data['payload'] ?? [];

        // `array_key_exists`, not `??`, and only for $format. Every other member has existed for
        // the whole 1.x line, so one of them MISSING is corruption rather than an older release
        // — while `??` would fill it with the same value the coercion produces and the intactness
        // check below could never see it. The same distinction makes an explicit null $format
        // corruption rather than a legacy envelope.
        $rawFormat = array_key_exists('format', $data) ? $data['format'] : PayloadFormat::Json;

        $id = is_string($rawId) ? $rawId : null;
        $type = is_string($rawType) ? $rawType : null;
        $createdAt = is_int($rawCreatedAt) ? $rawCreatedAt : null;
        $envelope = is_array($rawData) ? $rawData : [];
        $payload = is_array($rawPayload) ? $rawPayload : [];

        // Every member came through unchanged, so this is an envelope an older release wrote
        // rather than a corrupt one. Anything discarded above means the opposite.
        $intact = $id === $rawId
            && $type === $rawType
            && $createdAt === $rawCreatedAt
            && $envelope === $rawData
            && $payload === $rawPayload
            && array_key_exists('id', $data)
            && array_key_exists('type', $data)
            && array_key_exists('createdAt', $data)
            && array_key_exists('data', $data)
            && array_key_exists('payload', $data);

        $this->id = $id;
        $this->type = $type;
        $this->createdAt = $createdAt;
        $this->data = $envelope;
        $this->payload = $payload;
        $this->format = $intact && $rawFormat instanceof PayloadFormat ? $rawFormat : PayloadFormat::Unreadable;
    }

    /**
     * Parse the raw body into an envelope. The content type is what admits a form body;
     * JSON is read whether or not the producer declared it, so a wrong or missing header
     * can never cost a JSON delivery its payload. A body nothing could read yields an
     * empty payload marked {@see PayloadFormat::Unreadable}. The wire id is used as a
     * fallback envelope id so a handler always has a stable identifier even for a
     * bodyless notification.
     */
    public static function fromRawBody(string $rawBody, ?string $webhookId = null, ?string $contentType = null): self
    {
        [$format, $payload] = BodyDecoder::decode($rawBody, $contentType);

        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $createdAt = $payload['created_at'] ?? null;
        $data = $payload['data'] ?? null;

        return new self(
            id: is_string($id) ? $id : (is_int($id) ? (string) $id : $webhookId),
            type: is_string($type) ? $type : null,
            createdAt: is_int($createdAt) ? $createdAt : null,
            data: is_array($data) ? $data : $payload,
            payload: $payload,
            format: $format,
        );
    }
}
