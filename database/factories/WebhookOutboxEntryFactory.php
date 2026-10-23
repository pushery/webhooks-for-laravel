<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Pushery\Webhooks\Enums\OutboxEntryState;
use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;
use Pushery\Webhooks\Server\PendingWebhook;

/**
 * @extends Factory<WebhookOutboxEntry>
 */
final class WebhookOutboxEntryFactory extends Factory
{
    protected $model = WebhookOutboxEntry::class;

    public function definition(): array
    {
        $messageId = (string) Str::uuid7();
        $data = PendingWebhook::create()
            ->url('https://example.com/webhooks')
            ->payload(['invoice_id' => 'in_'.Str::random(12)])
            ->useSecret('whsec_'.base64_encode('outbox-factory'))
            ->useMessageId($messageId)
            ->forEventType('invoice.paid')
            ->toDeliveryData();

        return [
            'message_id' => $messageId,
            'url' => 'https://example.com/webhooks',
            'event_type' => 'invoice.paid',
            'meta' => [],
            'delivery' => Crypt::encrypt($data),
            'state' => OutboxEntryState::Pending,
            'attempts' => 0,
            'next_attempt_at' => Date::now(),
        ];
    }

    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'state' => OutboxEntryState::Delivered,
            'attempts' => 1,
            'last_status' => 200,
            'next_attempt_at' => null,
            'ended_at' => Date::now(),
        ]);
    }

    public function refused(): self
    {
        return $this->state(fn (): array => [
            'state' => OutboxEntryState::Refused,
            'attempts' => 1,
            'last_status' => 422,
            'last_error' => 'Webhook delivery failed.',
            'next_attempt_at' => null,
            'ended_at' => Date::now(),
        ]);
    }
}
