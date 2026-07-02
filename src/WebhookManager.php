<?php

declare(strict_types=1);

namespace Webhooks;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\WebhookServer\WebhookCall;
use Webhooks\Contracts\EndpointGuard;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Exceptions\InvalidPayloadException;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Signing\WebhookSigner;
use Webhooks\Support\PayloadValidator;
use Webhooks\Support\WebhookConfig;

/**
 * The package's public entry point: register endpoints, fan an event out to every
 * matching subscription, and test or replay individual deliveries. Each delivery
 * is dispatched through spatie/laravel-webhook-server (queue, retry, backoff)
 * signed with {@see WebhookSigner} and delivered by the SSRF-hardened
 * {@see GuardedWebhookCall} job.
 */
final readonly class WebhookManager
{
    public function __construct(
        private EndpointGuard $guard,
        private WebhookConfig $config,
        private PayloadValidator $payloadValidator,
    ) {}

    /**
     * Register a new endpoint. The URL is SSRF-validated before it is stored.
     *
     * @param  array<array-key, string>  $eventTypes
     */
    public function subscribe(?Model $owner, string $url, array $eventTypes, ?string $name = null): WebhookSubscription
    {
        $this->guard->validate($url);

        $subscription = new WebhookSubscription;
        $subscription->name = $name;
        $subscription->url = $url;
        $subscription->event_types = array_values($eventTypes);
        $subscription->is_active = true;
        $subscription->secret = $this->generateSecret();

        if ($owner instanceof Model) {
            $subscription->owner()->associate($owner);
        }

        $subscription->save();

        return $subscription;
    }

    /**
     * Fan an event out to every active subscription that listens for it, scoped to
     * the given tenant (plus any global, owner-less subscriptions). When payload
     * validation is enabled, the payload is checked against the event type's
     * catalog schema first, so a malformed event is rejected before any delivery.
     *
     * @param  array<array-key, mixed>  $payload
     * @return Collection<int, WebhookDelivery>
     *
     * @throws InvalidPayloadException
     */
    public function dispatch(string $eventType, array $payload, ?Model $tenant = null): Collection
    {
        $this->payloadValidator->validate($eventType, $payload);

        $eventId = (string) Str::uuid7();

        return WebhookSubscription::query()
            ->active()
            ->listeningFor($eventType)
            ->forTenant($tenant)
            ->get()
            ->reject(fn (WebhookSubscription $subscription): bool => $this->isRateLimited($subscription))
            ->map(fn (WebhookSubscription $subscription): WebhookDelivery => $this->deliver($subscription, $eventType, $eventId, $payload))
            ->values();
    }

    /**
     * Send a one-off test event to a single subscription (rate limit bypassed).
     */
    public function ping(WebhookSubscription $subscription): WebhookDelivery
    {
        return $this->deliver(
            $subscription,
            'webhooks.ping',
            (string) Str::uuid7(),
            ['message' => 'This is a test event from Webhooks for Laravel.'],
        );
    }

    /**
     * Replay a delivery. A fresh log entry is created but the original event id is
     * kept, so a consumer that deduplicates on it treats this as the same event.
     */
    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        return $this->deliver(
            $delivery->subscription,
            $delivery->event_type,
            $delivery->event_id,
            $delivery->payload,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function deliver(WebhookSubscription $subscription, string $eventType, string $eventId, array $data): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'subscription_id' => $subscription->id,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'payload' => $data,
            'status' => DeliveryStatus::Pending,
            'attempt' => 0,
        ]);

        $this->dispatchCall($subscription, $delivery, $eventType, $eventId, $data);

        return $delivery;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function dispatchCall(WebhookSubscription $subscription, WebhookDelivery $delivery, string $eventType, string $eventId, array $data): void
    {
        $envelope = [
            'id' => $eventId,
            'type' => $eventType,
            'created_at' => now()->toISOString(),
            'data' => $data,
        ];

        // The delivery job is the globally-installed GuardedWebhookCall (see the
        // service provider), which hardens exactly the calls carrying our meta.
        WebhookCall::create()
            ->url($subscription->url)
            ->payload($envelope)
            ->useSecret($subscription->signingSecrets())
            ->signUsing(WebhookSigner::class)
            ->maximumTries($this->config->tries())
            ->timeoutInSeconds($this->config->timeout())
            ->verifySsl($this->config->verifySsl())
            ->onQueue($this->config->queue())
            ->onConnection($this->config->connection())
            ->meta([
                'delivery_id' => $delivery->id,
                'subscription_id' => $subscription->id,
                'event_id' => $eventId,
            ])
            ->withHeaders([
                'X-Webhook-Id' => $eventId,
                'X-Webhook-Event' => $eventType,
            ])
            ->withTags($this->tags($subscription, $eventType))
            ->dispatch();
    }

    private function isRateLimited(WebhookSubscription $subscription): bool
    {
        if (! $this->config->rateLimitEnabled()) {
            return false;
        }

        $key = "webhooks:dispatch:{$subscription->id}";

        if (RateLimiter::tooManyAttempts($key, $this->config->rateLimitPerMinute())) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    /**
     * @return list<string>
     */
    private function tags(WebhookSubscription $subscription, string $eventType): array
    {
        if (! $this->config->horizonTags()) {
            return [];
        }

        return ['webhooks', "subscription:{$subscription->id}", "event:{$eventType}"];
    }

    private function generateSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
