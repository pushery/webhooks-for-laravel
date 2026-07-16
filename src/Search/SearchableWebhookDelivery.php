<?php

declare(strict_types=1);

namespace Webhooks\Search;

use Webhooks\Models\WebhookDelivery;

/**
 * A ready-made searchable delivery-log model: the standard {@see WebhookDelivery}
 * with the {@see SearchableDelivery} trait applied. Point
 * webhooks.dashboard.source_model at this class (with laravel/scout installed and
 * webhooks.search.enabled true) to get a searchable outbound delivery log without
 * writing any code. It shares the webhook_deliveries table, so it indexes exactly
 * the rows the engine writes.
 */
final class SearchableWebhookDelivery extends WebhookDelivery implements Indexed
{
    use SearchableDelivery;
}
