<?php

declare(strict_types=1);

namespace Webhooks\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Webhooks\Client\Verification\InboundVerifier;
use Webhooks\Client\WebhookConfig;
use Webhooks\Core\Signing\VerificationStatus;

/**
 * Fired when an incoming request fails verification. Carries the coarse reason only —
 * never which part failed — so a listener can alert or rate-limit an abusive source
 * without the receiver leaking verification detail to an untrusted caller.
 *
 * `reason` is the {@see VerificationStatus} value, and one of them
 * changes what a listener should do: `undetermined` means the check did not complete (an
 * {@see InboundVerifier} whose provider callback timed out),
 * not that the delivery was rejected. Counting it towards an abuse signal turns every
 * provider outage into an attack alert; counting only the others leaves the outage
 * invisible. They are different events and deserve different listeners.
 *
 * The request is then answered with the config's invalid_status (401 by default) — or, for
 * an undetermined verification, with undetermined_status when the host configured one.
 * Never a 500.
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
