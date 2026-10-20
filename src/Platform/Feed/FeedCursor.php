<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Feed;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Pushery\Webhooks\Exceptions\InvalidFeedCursor;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * The place a reader of an event feed stands: the creation time and the id of the last delivery
 * it read, in the order the feed walks the log.
 *
 * The time travels exactly as the database returned it and is bound back unchanged: with its
 * offset from PostgreSQL, and as the UTC wall clock MySQL stores. Either way the seek compares a
 * stored value with itself, whatever zone the session runs in. Encoded as URL-safe base64, so it
 * passes through a query string as it is.
 *
 * @internal
 */
final readonly class FeedCursor
{
    /** A timestamp as an engine returns one: date, time, optional fraction, optional offset. */
    private const string STORED_TIME = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}(:?\d{2})?)?$/';

    private function __construct(
        public string $createdAt,
        public string $deliveryId,
    ) {}

    public static function at(WebhookDelivery $delivery): self
    {
        $createdAt = $delivery->getRawOriginal('created_at');
        $key = $delivery->getKey();

        // Neither '' fallback is taken: the feed reads its rows from the log, and a row read from the
        // database carries its created_at as the string the engine returned and its key as a string.
        return new self(is_string($createdAt) ? $createdAt : '', is_string($key) || is_int($key) ? (string) $key : '');
    }

    /**
     * @throws InvalidFeedCursor when the value is not a cursor this class encoded
     */
    public static function decode(string $cursor): self
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || substr_count($decoded, '|') !== 1) {
            throw InvalidFeedCursor::because('it does not decode to a position');
        }

        [$createdAt, $deliveryId] = explode('|', $decoded);

        if (preg_match(self::STORED_TIME, $createdAt) !== 1) {
            throw InvalidFeedCursor::because('it names no creation time');
        }

        if (! Str::isUuid($deliveryId)) {
            throw InvalidFeedCursor::because('it names no delivery');
        }

        return new self($createdAt, $deliveryId);
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode($this->createdAt.'|'.$this->deliveryId), '+/', '-_'), '=');
    }

    /** The instant the stored time stands for, read the way the engine wrote it. */
    public function instant(): CarbonImmutable
    {
        return Timestamp::read(WebhookConnection::dialect(), $this->createdAt);
    }
}
