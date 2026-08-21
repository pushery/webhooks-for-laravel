<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Pushery\Webhooks\Client\Verification\InboundVerifier;
use Pushery\Webhooks\Core\Signing\VerificationStatus;

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
 *
 * ⚠️ IT CARRIES FIELDS, NOT THE REQUEST AND NOT THE CONFIG, and both omissions are
 * correctness requirements rather than preferences — the same rule
 * {@see UnreadableWebhookPayload} states for the same reasons.
 *
 * **No Request.** A listener may be queued, and Laravel serializes a job even on the `sync`
 * driver; an `Illuminate\Http\Request` holds the framework's user and route resolvers as
 * closures, which cannot be serialized. The throw landed INSIDE `dispatch()`, before the
 * `abort()` that answers 401 — so every forged, unsigned or expired POST came back 500, from
 * an anonymous caller, on the default configuration, on the one path this docblock promises is
 * never a 500. The listener never ran either, so the rate-limiting it was queued for did not
 * happen. The invitation above was a trap for exactly as long as the request travelled.
 *
 * **No WebhookConfig.** It holds the signing secret and the rotation secret in cleartext, so
 * any listener that logged the event, or any reporter that serialized it after one threw,
 * wrote `whsec_…` into the log and into the queue store — a copy no retention policy on
 * anything covers, and one that needed no queue at all. `source` is the config's name, and
 * `Pushery\Webhooks\Client\WebhookConfig::forName($event->source)` returns the whole
 * config, resolved from configuration rather than from a payload that travelled through a store.
 *
 * What is here is what an abuse listener acts on: who ({@see self::$ip}), where
 * ({@see self::$path}), what happened ({@see self::$reason}) and which producer
 * ({@see self::$source}). {@see self::$userAgent} is the field that answers the first
 * triage question — a scanner, or our own producer misconfigured by the last deploy.
 * A listener that needs more of the request has it: it runs during the request, so
 * `request()` is right there. That listener must not be queued.
 */
final class InvalidWebhookSignature
{
    use Dispatchable;

    public function __construct(
        public string $source,
        public string $reason,
        public ?string $ip,
        public string $path,
        public ?string $userAgent,
    ) {}
}
