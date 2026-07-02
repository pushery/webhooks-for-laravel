<?php

declare(strict_types=1);

namespace Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => WebhookSubscription::factory(),
            'event_type' => 'invoice.paid',
            'event_id' => (string) Str::uuid7(),
            'payload' => ['invoice_id' => 'in_123'],
            'status' => DeliveryStatus::Pending,
            'attempt' => 0,
        ];
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Succeeded,
            'attempt' => 1,
            'response_code' => 200,
            'response_ms' => 42,
            'delivered_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'attempt' => 1,
            'response_code' => 500,
            'error' => 'Webhook call failed',
        ]);
    }

    public function exhausted(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Exhausted,
            'attempt' => 3,
            'response_code' => 500,
            'error' => 'Webhook call failed',
        ]);
    }
}
