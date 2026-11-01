<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing\Exceptions;

use InvalidArgumentException;
use Pushery\Webhooks\Core\Signing\SignedRequest;
use Pushery\Webhooks\Core\Signing\SignsRequests;
use Pushery\Webhooks\Core\Signing\VerificationResult;
use Pushery\Webhooks\Core\Signing\WebhookMessage;

/**
 * Thrown when a {@see WebhookMessage} is constructed with
 * an id or timestamp that violates the Standard Webhooks rules (a "." in the id,
 * an empty id, or a non-positive timestamp), when {@see SignedRequest} is handed
 * a method or a target it cannot sign, and when {@see SignsRequests} is asked to
 * sign a call that would follow a redirect. This is a programmer error on the
 * sending side — never confused with untrusted receive-side input, which is
 * reported as a {@see VerificationResult} instead.
 */
final class InvalidMessage extends InvalidArgumentException
{
    public static function id(string $id): self
    {
        return new self("A webhook message id must be non-empty and must not contain a '.', got: [{$id}].");
    }

    public static function timestamp(int $timestamp): self
    {
        return new self("A webhook message timestamp must be a positive Unix time, got: [{$timestamp}].");
    }

    public static function method(string $method): self
    {
        return new self("A signed request needs its method as an HTTP token, got: [{$method}].");
    }

    public static function target(string $target): self
    {
        return new self("A signed request needs its target as a path from '/' with its query and without whitespace, got: [{$target}].");
    }

    public static function followsRedirects(): self
    {
        return new self(
            'A signed call must not follow redirects: a followed redirect is a second request, to an address the '
            .'receiver chose, and it would be signed as well. Call withoutRedirecting() on the request.',
        );
    }
}
