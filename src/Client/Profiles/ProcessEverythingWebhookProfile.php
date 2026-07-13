<?php

declare(strict_types=1);

namespace Webhooks\Client\Profiles;

use Illuminate\Http\Request;

/**
 * The default profile: process every verified request. Swap in a custom profile
 * (per config entry) to filter by event type or any request attribute.
 */
final class ProcessEverythingWebhookProfile implements WebhookProfile
{
    public function shouldProcess(Request $request): bool
    {
        return true;
    }
}
