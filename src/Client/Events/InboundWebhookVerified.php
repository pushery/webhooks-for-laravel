<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Pushery\Webhooks\Client\Exceptions\InboundListenerFailed;
use Pushery\Webhooks\Client\Verification\InboundVerifier;
use Pushery\Webhooks\Core\Signing\SecretSet;
use Pushery\Webhooks\Core\Signing\VerificationResult;

/**
 * Fired when an inbound delivery has been authenticated, naming the secret that verified it.
 *
 * It exists for one question, and it is the only one the package could not answer about itself:
 * **during a rotation, is anything still arriving on the old secret?** `previous_secret` keeps a
 * producer's retired key verifying while it migrates, and the window has to be closed by hand.
 * Closed too early, genuine deliveries start bouncing; left open, a retired secret keeps working.
 * {@see VerificationResult} has carried the answer from the start — its docblock says "so a
 * rotation can be observed" — and every shipped scheme fills it in. Nothing read it back, so the
 * promise was kept by the schemes and dropped by the pipeline.
 *
 * `matchedKeyId` is {@see SecretSet::CURRENT} or {@see SecretSet::PREVIOUS} for the static-secret
 * path, the JWKS `kid` when the keys come from a JWKS document, and whatever a custom
 * {@see InboundVerifier} reported. It is null only when a
 * verifier authenticated the request without naming a key.
 *
 * ⚠️ IT FIRES ON EVERY VERIFIED DELIVERY, not only when the previous key matched, and that is the
 * design rather than a default anyone can trim. Firing only on `previous` makes silence
 * ambiguous: no events means either the migration finished or no traffic arrived at all, and
 * those two call for opposite actions. It is the same distinction the refusal path draws between
 * a refusal and an absence. A listener that only wants the rare case filters on `matchedKeyId`;
 * a listener that wants the ratio needs both, and cannot reconstruct it from a filtered stream.
 *
 * It carries the config's NAME rather than the config, like its two siblings, because a
 * `Pushery\Webhooks\Client\WebhookConfig` holds the signing and rotation secrets in cleartext —
 * and a rotation listener is the single most likely one to be queued, since it is bookkeeping
 * rather than request work. `WebhookConfig::forName($event->source)` returns the whole config.
 *
 * A listener that throws cannot cost the delivery: the dispatch is guarded and the failure is
 * reported as {@see InboundListenerFailed}. The delivery is
 * authentic and already verified at this point, so answering the producer 500 over a broken
 * ledger would ask it to retry something that succeeded.
 */
final class InboundWebhookVerified
{
    use Dispatchable;

    public function __construct(
        public string $source,
        public ?string $matchedKeyId,
    ) {}
}
