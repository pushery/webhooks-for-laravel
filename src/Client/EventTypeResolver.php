<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client;

use Pushery\Webhooks\Core\Signing\SignatureHeaders;

/**
 * Derives the event type of one inbound delivery when it lives somewhere the built-in
 * `event_type` strategies (`header:Name`, `body:dotted.path`) cannot reach.
 *
 * The case that motivated this is GitHub, and it is not exotic: the useful type is the
 * header AND a body field together — `X-GitHub-Event: release` plus `action: published`
 * is one event, `release.published`, and neither half alone routes anything. A resolver
 * composes them without the configuration growing a special case per producer.
 *
 * Return null when this delivery carries no usable type. That is the same thing as not
 * configuring one, and it is honest: an empty `event_type` column says "this producer
 * does not name its events", while a made-up value says something false about them.
 *
 * Called only after the signature is verified, so the body is authentic.
 */
interface EventTypeResolver
{
    /**
     * @param  array<array-key, mixed>  $payload  the decoded body — JSON, or the fields of a
     *                                            form the producer declared as such — and an
     *                                            empty array when nothing could read it
     */
    public function resolve(array $payload, string $rawBody, SignatureHeaders $headers): ?string;
}
