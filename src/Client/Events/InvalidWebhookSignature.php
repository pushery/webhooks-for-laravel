<?php

declare(strict_types=1);

namespace Webhooks\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Webhooks\Client\WebhookConfig;

/**
 * Fired when an incoming request fails signature verification (bad, expired or
 * malformed). Carries the coarse reason only — never which part failed — so a
 * listener can alert or rate-limit an abusive source without the receiver leaking
 * verification detail to an untrusted caller. The request is then answered with the
 * config's invalid_status (401 by default), never a 500.
 */
final class InvalidWebhookSignature
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public WebhookConfig $config,
        public string $reason,
    ) {}
}
