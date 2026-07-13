<?php

declare(strict_types=1);

namespace Webhooks\Server\Facades;

use Webhooks\Server\PendingWebhook;

/**
 * A thin, discoverable entry point to the {@see PendingWebhook} builder. The builder
 * class API is first-class; this exists so `WebhookSender::to($url)` reads well and
 * shows up in IDE autocompletion.
 */
final class WebhookSender
{
    public static function create(): PendingWebhook
    {
        return PendingWebhook::create();
    }

    public static function to(string $url): PendingWebhook
    {
        return PendingWebhook::create()->url($url);
    }
}
