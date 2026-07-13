<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Webhooks\Core\Signing\Exceptions\InvalidMessage;

/**
 * The immutable, signed unit of a webhook: a stable id, a Unix timestamp, and the
 * exact raw body bytes. The id and timestamp are part of the signed content and,
 * per the Standard Webhooks security model, must not be user-controlled and must
 * not contain a "." (the field separator in the signed string {id}.{ts}.{body}).
 *
 * The id is STABLE across delivery attempts: a retried delivery re-signs with the
 * same id, so the receiver's idempotency key never changes.
 */
final readonly class WebhookMessage
{
    public function __construct(
        public string $id,
        public int $timestamp,
        public string $rawBody,
    ) {
        if ($id === '' || str_contains($id, '.')) {
            throw InvalidMessage::id($id);
        }

        if ($timestamp <= 0) {
            throw InvalidMessage::timestamp($timestamp);
        }
    }

    /**
     * A fresh message with a server-generated UUIDv7 id and the current time. Use
     * this for a first dispatch; use {@see self::for()} to re-sign a retry with a
     * caller-supplied stable id.
     */
    public static function create(string $rawBody): self
    {
        return new self((string) Str::uuid7(), Date::now()->getTimestamp(), $rawBody);
    }

    /**
     * A message with a caller-supplied stable id (and optional timestamp). The
     * timestamp defaults to now so that queue dwell never expires a legitimate
     * signature — deliveries are (re)signed at send time.
     */
    public static function for(string $rawBody, string $id, ?int $timestamp = null): self
    {
        return new self($id, $timestamp ?? Date::now()->getTimestamp(), $rawBody);
    }
}
