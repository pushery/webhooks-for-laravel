<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Exceptions;

use InvalidArgumentException;
use Pushery\Webhooks\WebhookManager;

/**
 * The `after` handed to {@see WebhookManager::feed()} is not a cursor the feed wrote. A receiver
 * that sends one has lost its place, and reading on from a guess could skip events, so the feed
 * refuses it instead. Answer it the way a request with an invalid parameter is answered, with a
 * 422 for example, and let the receiver start again without `after`.
 */
final class InvalidFeedCursor extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self('The feed cursor is not one the feed handed out: '.$reason.'.');
    }
}
