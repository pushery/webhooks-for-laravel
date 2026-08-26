<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Exceptions;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * A client config carries no way to authenticate anything: no 'secret', no 'jwks' url and
 * no 'verifier'. Every request to that endpoint is unverifiable, and no retry can change
 * that.
 *
 * It extends InvalidArgumentException, which is what this condition has always thrown, so
 * a host already catching that still catches this. What it adds is the two things the bare
 * exception could not carry: the status the endpoint should answer with, and a report()
 * that says the fault is a CONFIGURATION rather than an application error.
 *
 * The status matters more than it looks. A configuration fault is the one refusal that used
 * to escape as a 500 — and 5xx is the only answer a producer reads as "try again". Some do
 * not retry at all, so for them a 500 does not delay the delivery, it loses it; others
 * disable an endpoint that keeps failing. The endpoint answers the same refusal a rejected
 * signature gets, because it is the same fact about the request: it cannot be accepted as
 * authentic, and trying again will not help.
 */
final class WebhookConfigCannotVerify extends InvalidArgumentException
{
    private function __construct(
        public readonly string $configName,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(string $name, int $status): self
    {
        return new self(
            $name,
            $status,
            "The webhook client config [{$name}] requires a non-empty 'secret', a 'jwks' url, or a 'verifier'.",
        );
    }

    /**
     * Report it as the configuration fault it is, and consider it reported.
     *
     * The default handler would file this under application errors, next to the failures
     * that live in code — which is where whoever reads it would then go looking. The
     * message names the config and the three ways to fix it, and carries nothing from the
     * request: on this path the body is unauthenticated input from whoever found the URL.
     */
    public function report(): bool
    {
        Log::error($this->getMessage(), [
            'webhook_client_config' => $this->configName,
            'answered_with' => $this->status,
        ]);

        return true;
    }
}
