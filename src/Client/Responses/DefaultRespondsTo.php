<?php

declare(strict_types=1);

namespace Webhooks\Client\Responses;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The default acknowledgement: a 200 with a plain "ok" body.
 */
final class DefaultRespondsTo implements RespondsToWebhook
{
    public function respond(Request $request): Response
    {
        return new Response('ok');
    }
}
