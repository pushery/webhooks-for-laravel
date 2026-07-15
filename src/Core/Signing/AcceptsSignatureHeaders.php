<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * A signature scheme that can be told, per Client config, which header names to read the
 * signature (and, where the dialect has them, the id and timestamp) from — so a host can
 * point a shipped scheme at a provider that uses different header names without writing a
 * scheme class of its own.
 *
 * A null argument means "not configured — keep this scheme's own default", so a scheme
 * whose default header is provider-specific (GitHub's `X-Hub-Signature-256`, the plain
 * HMAC `Signature`) is never clobbered by the Standard-Webhooks fallback the config uses
 * for its other header accessors. Only the names the host EXPLICITLY set are passed here.
 *
 * The two first-class schemes ({@see StandardWebhooksScheme}, {@see Ed25519Scheme}) receive
 * their header names through their constructor instead and need not implement this; it
 * exists for every OTHER scheme, which the config resolves from the container with no
 * constructor arguments.
 */
interface AcceptsSignatureHeaders
{
    public function withSignatureHeaders(?string $idHeader, ?string $timestampHeader, ?string $signatureHeader): SignatureScheme;
}
