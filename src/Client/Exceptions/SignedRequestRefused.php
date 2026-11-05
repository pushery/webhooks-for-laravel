<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Exceptions;

use Pushery\Webhooks\Client\Events\InvalidWebhookSignature;
use Pushery\Webhooks\Client\Http\VerifySignedRequest;
use Pushery\Webhooks\Core\Signing\VerificationResult;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A call to a route of the application's own API that {@see VerifySignedRequest} refused.
 *
 * It is an HttpException carrying the status the config answers a refusal with, and no message, so an application that
 * renders nothing of its own answers exactly as `abort()` with that status would: the framework renders an
 * HttpException by its status and does not report it. An application with an error format of its own renders it:
 *
 *     ->withExceptions(function (Exceptions $exceptions): void {
 *         $exceptions->render(fn (SignedRequestRefused $refused) => response()->json([
 *             'type' => 'urn:example:problem:invalid-signature',
 *             'status' => $refused->getStatusCode(),
 *         ], $refused->getStatusCode(), ['Content-Type' => 'application/problem+json']));
 *     });
 *
 * `reason` is the verdict {@see InvalidWebhookSignature} announces (`invalid`, `expired`, `malformed` or
 * `undetermined`), or `unverifiable` for a config that cannot verify any call; that case is reported as the
 * configuration fault it is and carries the {@see WebhookConfigCannotVerify} as its previous exception. Nothing in it
 * comes from the request: on this path the request is unauthenticated input.
 */
final class SignedRequestRefused extends HttpException
{
    /**
     * The reason of a refusal by a config that cannot verify any call.
     */
    public const string UNVERIFIABLE = 'unverifiable';

    private function __construct(
        public readonly string $configName,
        public readonly string $reason,
        int $status,
        ?WebhookConfigCannotVerify $fault = null,
    ) {
        parent::__construct($status, '', $fault);
    }

    /**
     * A call whose signature the config checked and did not accept, answered with the given status.
     */
    public static function because(string $configName, VerificationResult $result, int $status): self
    {
        return new self($configName, $result->reason(), $status);
    }

    /**
     * A call to a route whose config cannot verify any call, answered with the status the fault carries.
     */
    public static function unverifiable(WebhookConfigCannotVerify $fault): self
    {
        return new self($fault->configName, self::UNVERIFIABLE, $fault->status, $fault);
    }
}
