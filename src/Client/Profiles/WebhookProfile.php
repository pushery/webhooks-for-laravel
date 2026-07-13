<?php

declare(strict_types=1);

namespace Webhooks\Client\Profiles;

use Illuminate\Http\Request;

/**
 * Decides whether an incoming (already verified) request is worth storing and
 * processing, so an app can drop event types it does not care about before they
 * ever touch the database or the queue.
 */
interface WebhookProfile
{
    public function shouldProcess(Request $request): bool;
}
