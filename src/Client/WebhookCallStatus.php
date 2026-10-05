<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client;

/**
 * Lifecycle state of a stored incoming webhook call. A call is stored as Received and moves to
 * Processed once its handler returns, or to Failed once the queue gives up on it after the last
 * attempt. A status the handler writes itself is kept.
 */
enum WebhookCallStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
}
