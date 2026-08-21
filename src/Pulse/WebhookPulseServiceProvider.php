<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Pulse;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Pulse;
use Livewire\Livewire;
use Override;
use Pushery\Webhooks\Server\Events\WebhookAttemptsExhausted;
use Pushery\Webhooks\Server\Events\WebhookAttemptSucceeded;
use Pushery\Webhooks\Support\MergesPackageConfig;

/**
 * Boots the internal-ops Laravel Pulse integration — the delivery recorder and its
 * card — but ONLY when it is both wanted and possible: webhooks.pulse.enabled is on
 * AND laravel/pulse is actually installed. It is deliberately NOT auto-registered
 * (absent from composer extra.laravel.providers), so a consumer who neither runs Pulse
 * nor wants the internal monitor pays nothing and nothing boots.
 *
 * This is the single-view engineering monitor, distinct from the multi-tenant
 * customer dashboard (Pushery\Webhooks\Dashboard). laravel/pulse is a Composer suggestion, so
 * the recorder and card are referenced directly here but every runtime path is guarded
 * by class_exists + config before anything touches Pulse.
 */
final class WebhookPulseServiceProvider extends ServiceProvider
{
    use MergesPackageConfig;

    #[Override]
    public function register(): void
    {
        $this->mergePackageConfig();
    }

    /**
     * The single condition the whole integration hangs off, exposed so it is directly
     * testable: the opt-in flag AND the optional dependency both being present.
     */
    public function shouldBoot(): bool
    {
        return Config::boolean('webhooks.pulse.enabled', false)
            && class_exists(Pulse::class);
    }

    public function boot(): void
    {
        if (! $this->shouldBoot()) {
            return;
        }

        // Wire the recorder to the Server delivery events directly — the same events
        // it exposes via its $listen array — so the host need not edit pulse.recorders.
        Event::listen(
            [WebhookAttemptSucceeded::class, WebhookAttemptsExhausted::class],
            [WebhookDeliveryRecorder::class, 'record'],
        );

        Livewire::component('webhooks.pulse.deliveries', WebhookDeliveryCard::class);
    }
}
