<?php

declare(strict_types=1);

namespace Webhooks\Search;

use Webhooks\Client\Models\WebhookCall;

/**
 * A ready-made searchable inbound call-log model: the standard {@see WebhookCall}
 * with the {@see SearchableCall} trait applied. Point a client config's 'model' at
 * this class (with laravel/scout installed and webhooks.search.enabled true) to get
 * a searchable inbound call log without writing any code. It shares the
 * webhook_calls table, so it indexes exactly the calls the receiver stores.
 */
final class SearchableWebhookCall extends WebhookCall implements Indexed
{
    use SearchableCall;
}
