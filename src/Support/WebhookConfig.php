<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Illuminate\Support\Facades\Config;

/**
 * Typed reader over the package configuration, so the rest of the code never
 * juggles mixed config values. Every accessor carries the same default as
 * config/webhooks.php, so a partially-published config (mergeConfigFrom only
 * shallow-merges top-level keys) can never leave a nested key undefined.
 */
final class WebhookConfig
{
    public function tries(): int
    {
        return Config::integer('webhooks.delivery.tries', 3);
    }

    public function timeout(): int
    {
        return Config::integer('webhooks.delivery.timeout', 5);
    }

    public function verifySsl(): bool
    {
        return Config::boolean('webhooks.delivery.verify_ssl', true);
    }

    public function queue(): string
    {
        return Config::string('webhooks.delivery.queue', 'default');
    }

    public function connection(): ?string
    {
        $connection = Config::get('webhooks.delivery.connection');

        return is_string($connection) ? $connection : null;
    }

    public function rateLimitEnabled(): bool
    {
        return Config::boolean('webhooks.rate_limit.enabled', true);
    }

    public function rateLimitPerMinute(): int
    {
        return Config::integer('webhooks.rate_limit.max_per_minute', 60);
    }

    public function circuitBreakerEnabled(): bool
    {
        return Config::boolean('webhooks.circuit_breaker.enabled', true);
    }

    public function circuitBreakerThreshold(): int
    {
        return Config::integer('webhooks.circuit_breaker.threshold', 10);
    }

    public function horizonTags(): bool
    {
        return Config::boolean('webhooks.horizon_tags', true);
    }

    public function retentionMonths(): int
    {
        return Config::integer('webhooks.retention_months', 3);
    }

    public function partitionMonthsAhead(): int
    {
        return Config::integer('webhooks.partition_months_ahead', 3);
    }

    public function validatePayloads(): bool
    {
        return Config::boolean('webhooks.validate_payloads', false);
    }

    /**
     * The JSON Schema declared for an event type in the catalog, or null when the
     * type declares none. The catalog is indexed by the literal event type (which
     * contains dots, e.g. "invoice.paid"), not via dot-notation config access.
     *
     * @return array<array-key, mixed>|null
     */
    public function schemaFor(string $eventType): ?array
    {
        $entry = Config::array('webhooks.catalog', [])[$eventType] ?? null;
        $schema = is_array($entry) ? ($entry['schema'] ?? null) : null;

        return is_array($schema) ? $schema : null;
    }
}
