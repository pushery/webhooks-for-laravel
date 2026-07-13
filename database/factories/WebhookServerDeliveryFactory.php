<?php

declare(strict_types=1);

namespace Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Server\Models\WebhookServerDelivery;

/**
 * @extends Factory<WebhookServerDelivery>
 */
final class WebhookServerDeliveryFactory extends Factory
{
    protected $model = WebhookServerDelivery::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'message_id' => 'msg_'.Str::random(24),
            'url' => 'https://example.com/webhooks',
            'event_type' => 'invoice.paid',
            'status' => DeliveryStatus::Pending,
            'attempt' => 0,
            'tags' => ['evt:invoice.paid'],
        ];
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Succeeded,
            'attempt' => 1,
            'http_status' => 200,
            'duration_ms' => 42,
            'delivered_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'attempt' => 1,
            'http_status' => 500,
            'error' => 'Webhook delivery failed.',
        ]);
    }

    public function exhausted(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Exhausted,
            'attempt' => 3,
            'http_status' => 500,
            'error' => 'Webhook delivery failed.',
        ]);
    }
}
