<?php

declare(strict_types=1);

namespace Webhooks\Client\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Produces the HTTP response returned to a producer for an accepted request. A
 * webhook sender only cares that it received a 2xx, so the default is a plain
 * 200 — but a producer with a specific ack contract can supply its own.
 */
interface RespondsToWebhook
{
    public function respond(Request $request): Response;
}
