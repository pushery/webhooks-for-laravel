<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Pushery\Webhooks\Client\Exceptions\InboundListenerFailed;
use Pushery\Webhooks\Client\Tenancy\InboundTenant;
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
 * `matchedKeyId` is {@see SecretSet::CURRENT} or {@see SecretSet::PREVIOUS} when a scheme
 * verified the delivery, and whatever a custom {@see InboundVerifier} reported. For a static
 * secret the two name the configured `secret` and `previous_secret`. For keys from a JWKS
 * document they name the first and the second Ed25519 key the document publishes,
 * by position, not by age: a JWK carries no reliable age, and its `kid` is not reported. With
 * a pinned `kid` the one key is `current`. It is null only when a verifier authenticated the
 * request without naming a key.
 *
 * `confirmingKeyIds` lists every key that confirms the delivery on its own, current first. A
 * scheme stops at the first key that matches, so `matchedKeyId` cannot tell a producer that
 * signs with both secrets from one that signs with the new one alone, and that difference is
 * the end of a rotation: the first delivery the previous key no longer confirms says the
 * producer has moved on, and the previous secret can go. With one key, with a custom
 * {@see InboundVerifier}, and with a scheme of the host's own, which the package does not call a
 * second time, the list holds `matchedKeyId`, or nothing when that is null.
 *
 * `tenant` is the key of the {@see InboundTenant} a config's `tenant_resolver` named for the
 * delivery, and null on a config without one. Its secrets verified the delivery, so the tenant
 * is proven, not claimed.
 *
 * It fires on every verified delivery, not only when the previous key matched, and that is the
 * design rather than a default anyone can trim. Firing only on `previous` makes silence
 * ambiguous: no events means either the migration finished or no traffic arrived at all, and
 * those two call for opposite actions. It is the same distinction the refusal path draws between
 * a refusal and an absence. A listener that only wants the rare case filters on `matchedKeyId`;
 * a listener that wants the ratio needs both, and cannot reconstruct it from a filtered stream.
 *
 * It carries the config's name rather than the config, like its two siblings, because a
 * `Pushery\Webhooks\Client\WebhookConfig` holds the signing and rotation secrets in cleartext —
 * and a rotation listener is the single most likely one to be queued, since it is bookkeeping
 * and not request work. `WebhookConfig::forName($event->source)` returns the whole config.
 *
 * A listener that throws cannot cost the delivery: the dispatch is guarded and the failure is
 * reported as {@see InboundListenerFailed}. The delivery is
 * authentic and already verified at this point, so answering the producer 500 over a broken
 * ledger would ask it to retry something that succeeded.
 */
final class InboundWebhookVerified
{
    use Dispatchable;

    /**
     * @param  list<string>  $confirmingKeyIds
     */
    public function __construct(
        public string $source,
        public ?string $matchedKeyId,
        public ?string $tenant = null,
        public array $confirmingKeyIds = [],
    ) {}
}
