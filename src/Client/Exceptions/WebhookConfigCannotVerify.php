<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Exceptions;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * A client config carries no way to authenticate anything -- no 'secret', no 'jwks' url and
 * no 'verifier', or no entry of that name at all. Every request to that endpoint is
 * unverifiable, and no retry can change that.
 *
 * The two shapes are one fact from the endpoint's side, which is why they share a class: a
 * producer must not be able to tell a config with nothing in it from a config that is not
 * there, and a host reading its own logs must not find two shapes for one event.
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
     * The secret is present but derives NO key, which is worse than absent.
     *
     * Standard Webhooks base64-decodes the secret to get its HMAC key. A value carrying no
     * base64 characters -- the bare `whsec_` prefix above all -- decodes to zero bytes, and
     * HMAC under an empty key is computable by anyone who sees the request. Absent, the
     * config refuses everything; like this, it would ACCEPT everything, while reading as
     * configured. The same refusal and the same status, because it is the same fact about
     * the request: it cannot be accepted as authentic.
     */
    public static function derivesNoKey(string $name, int $status): self
    {
        return new self(
            $name,
            $status,
            "The webhook client config [{$name}] has a 'secret' that base64-decodes to nothing, so it "
            .'would verify every forged delivery. Use the full secret, including the characters after '
            ."the 'whsec_' prefix.",
        );
    }

    /**
     * There is no entry of that name at all, which is the same fact carried further.
     *
     * The route does not vanish with the entry. It hangs on the macro, and the macro on
     * `webhooks.client.enabled` -- never on whether a client of that name is configured. So a
     * lost entry does not take the endpoint down; it turns it into a 500. A bad merge, an unset
     * variable in a fresh environment, a renamed client: none of them is exotic, and none of
     * them is visible until the first real delivery.
     *
     * The status is the package default rather than the entry's `invalid_status`, because there
     * is no entry to read one from. That is the honest answer and not a fallback: a host that
     * wants a different code for this case has nowhere to put it, and inventing a second
     * setting would mean configuring the behavior of a config that does not exist.
     */
    public static function notConfigured(string $name, int $status): self
    {
        return new self(
            $name,
            $status,
            "No webhook client config named [{$name}] is defined in webhooks.client.configs, so "
            .'nothing can verify a delivery to that endpoint. Add the entry, or remove the route '
            .'that points at it.',
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
