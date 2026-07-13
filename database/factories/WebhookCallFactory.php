<?php

declare(strict_types=1);

namespace Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Webhooks\Client\Models\WebhookCall;
use Webhooks\Client\WebhookCallStatus;

/**
 * @extends Factory<WebhookCall>
 */
final class WebhookCallFactory extends Factory
{
    protected $model = WebhookCall::class;

    public function definition(): array
    {
        $payload = ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'in_123']];
        $rawBody = (string) json_encode($payload);

        return [
            'source' => 'stripe',
            'webhook_id' => 'msg_'.Str::random(24),
            'event_type' => 'invoice.paid',
            'payload' => $payload,
            // A seeded row carries its bytes exactly as a received one does, so
            // hash(body()) === body_sha256 holds for a factory row too.
            'raw_body' => WebhookCall::encodeRawBody($rawBody),
            'body_sha256' => hash('sha256', $rawBody),
            'headers' => null,
            'status' => WebhookCallStatus::Received,
        ];
    }

    public function processed(): self
    {
        return $this->state(fn (): array => ['status' => WebhookCallStatus::Processed]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => WebhookCallStatus::Failed,
            'exception' => 'Handler threw an exception.',
        ]);
    }

    public function withoutWebhookId(): self
    {
        return $this->state(fn (): array => ['webhook_id' => null]);
    }
}
