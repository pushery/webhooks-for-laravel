<?php

declare(strict_types=1);

namespace Webhooks\Client\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Override;
use Webhooks\Client\Exceptions\CorruptRawBody;
use Webhooks\Client\WebhookCallStatus;
use Webhooks\Core\Payload\PayloadStore;
use Webhooks\Database\Concerns\HasZonedTimestamps;
use Webhooks\Database\Concerns\ScopesByTimestamp;
use Webhooks\Database\Concerns\UsesWebhookConnection;
use Webhooks\Database\Factories\WebhookCallFactory;

/**
 * A stored incoming webhook: the exact body hash, the (redacted) headers, the
 * parsed payload and its processing status. The primary key is a UUID and the row
 * is pruned once it ages past webhooks.client.delete_after_days.
 *
 * @property string $id
 * @property string $source
 * @property string|null $webhook_id
 * @property string|null $event_type
 * @property array<array-key, mixed> $payload
 * @property string|null $raw_body
 * @property string|null $payload_disk
 * @property string|null $payload_path
 * @property string $body_sha256
 * @property array<string, mixed>|null $headers
 * @property WebhookCallStatus $status
 * @property string|null $exception
 * @property string|null $payload_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookCall extends Model
{
    /** @use HasFactory<WebhookCallFactory> */
    use HasFactory;

    use HasUuids;
    use HasZonedTimestamps;
    use MassPrunable;
    use ScopesByTimestamp;
    use UsesWebhookConnection;

    protected $table = 'webhook_calls';

    protected $guarded = [];

    /**
     * The rows eligible for pruning: everything older than the configured window.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        // The cutoff is bound for THIS connection's dialect: MySQL converts an offset-bearing
        // literal into the database session time zone (8.0.19+), which would slide the retention
        // boundary by that offset and prune rows whose window has not closed yet.
        return static::query()->where(
            'created_at',
            '<=',
            $this->boundTimestamp(Date::now()->subDays(Config::integer('webhooks.client.delete_after_days', 30))),
        );
    }

    /**
     * The EXACT body that was received and signature-verified, byte for byte — so
     * hash('sha256', $call->body()) always equals $call->body_sha256, and the call can
     * be re-verified or forwarded later. An over-sized body offloaded to a Storage disk
     * is read back from there; every other call keeps its bytes in raw_body.
     *
     * The stored payload is the parsed, queryable VIEW of those bytes, not the bytes:
     * re-encoding it would change whitespace, escaping and float formatting, and a body
     * that never decoded would have nothing to re-encode.
     *
     * @throws CorruptRawBody when the stored bytes are not valid base64 — never on a row
     *                        this package wrote, only on one edited underneath it.
     */
    public function body(): string
    {
        if ($this->payload_disk !== null && $this->payload_path !== null) {
            return new PayloadStore()->rehydrate($this->payload_disk, $this->payload_path);
        }

        $decoded = $this->raw_body === null ? false : base64_decode($this->raw_body, true);

        return $decoded === false ? throw CorruptRawBody::for($this->id) : $decoded;
    }

    /**
     * Encode raw bytes for the raw_body column. Base64 because a received body is
     * arbitrary bytes — a NUL byte or an invalid UTF-8 sequence is rejected outright by
     * a Postgres text column, and a bytea column round-trips through PDO as a stream.
     */
    public static function encodeRawBody(string $rawBody): string
    {
        return base64_encode($rawBody);
    }

    protected static function newFactory(): WebhookCallFactory
    {
        return WebhookCallFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'status' => WebhookCallStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
