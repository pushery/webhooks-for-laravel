<?php

declare(strict_types=1);

namespace Webhooks\Client;

/**
 * Lifecycle state of a stored incoming webhook call. A call is stored as Received;
 * a handler advances it to Processed or Failed.
 */
enum WebhookCallStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
}
