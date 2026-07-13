<?php

declare(strict_types=1);

namespace Webhooks\Client;

/**
 * The parsed envelope of an incoming webhook: the producer's id, the event type, the
 * created-at timestamp and the data payload, read from the JSON body when it is
 * present. This is the RECEIVE-side view of a message — the one a handler job reads via
 * $this->message — as distinct from `Webhooks\Core\Signing\WebhookMessage`, which models
 * the exact bytes a signature is computed over. Every field is nullable because an
 * arbitrary producer need not follow any particular body convention.
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
    ) {}

    /**
     * Parse the raw body into an envelope. A non-array body (or invalid JSON) yields
     * an empty payload; the wire id is used as a fallback envelope id so a handler
     * always has a stable identifier even for a bodyless notification.
     */
    public static function fromRawBody(string $rawBody, ?string $webhookId = null): self
    {
        $decoded = json_decode($rawBody, true);
        $payload = is_array($decoded) ? $decoded : [];

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
        );
    }
}
