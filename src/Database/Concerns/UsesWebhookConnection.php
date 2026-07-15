<?php

declare(strict_types=1);

namespace Webhooks\Database\Concerns;

use Webhooks\Support\WebhookConnection;

/**
 * Binds a model to the package's configured database connection (webhooks.database.connection),
 * so every model reads and writes the same connection as the migrations and the raw analytics
 * queries — the app default unless a host points the package at a dedicated side-car connection.
 *
 * @internal
 */
trait UsesWebhookConnection
{
    public function getConnectionName(): ?string
    {
        return WebhookConnection::name();
    }
}
