<?php

declare(strict_types=1);

namespace Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Webhooks\Models\WebhookSubscription;

/**
 * @extends Factory<WebhookSubscription>
 */
final class WebhookSubscriptionFactory extends Factory
{
    protected $model = WebhookSubscription::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word().' '.$this->faker->word(),
            'url' => 'https://'.$this->faker->domainName().'/webhooks',
            'secret' => 'whsec_'.Str::random(40),
            'event_types' => ['invoice.paid'],
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'disabled_at' => now(),
        ]);
    }

    public function forOwner(Model $owner): self
    {
        return $this->state(fn (): array => [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $types
     */
    public function listeningFor(array $types): self
    {
        return $this->state(fn (): array => ['event_types' => $types]);
    }
}
