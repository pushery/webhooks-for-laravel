<?php

declare(strict_types=1);

namespace Webhooks\Client\Dedupe;

use Webhooks\Core\Signing\SignatureHeaders;

/**
 * Derives the idempotency key for one inbound delivery when it lives somewhere the
 * built-in `dedupe_id` strategies (`header:Name`, `body:dotted.path`) can't reach —
 * a value spread across two body fields, a base64 chunk of the raw bytes, a header
 * that needs decoding first. Point a config's `dedupe_id` at an implementation and the
 * container resolves it, so it may depend on anything a service needs.
 *
 * The key is what the partial-unique store and the fast-path cache key on: return a
 * value that is STABLE across a producer's retries of the same event (so the second
 * delivery is recognised as a duplicate) and DISTINCT between different events. Return
 * null when this delivery carries no usable key — the call is then always stored, never
 * silently swallowed. Called only after the signature is verified, so the body is
 * authentic.
 */
interface DedupeKeyResolver
{
    /**
     * @param  array<array-key, mixed>  $payload  the decoded JSON body, or an empty array for a non-JSON body
     */
    public function resolve(array $payload, string $rawBody, SignatureHeaders $headers): ?string;
}
