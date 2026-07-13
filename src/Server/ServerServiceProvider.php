<?php

declare(strict_types=1);

namespace Webhooks\Server;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use Webhooks\Server\Delivery\DeliveryGate;
use Webhooks\Server\Delivery\OpenDeliveryGate;
use Webhooks\Server\Delivery\ResponseClassifier;
use Webhooks\Server\Events\WebhookAttemptsExhausted;
use Webhooks\Server\Events\WebhookAttemptSucceeded;
use Webhooks\Server\Listeners\PersistServerDelivery;
use Webhooks\Server\Models\WebhookServerDelivery;
use Webhooks\Server\Signing\EncryptedSecretResolver;
use Webhooks\Server\Signing\SecretResolver;
use Webhooks\Server\Telemetry\NullSpanEmitter;
use Webhooks\Server\Telemetry\RecordDeliverySpan;
use Webhooks\Server\Telemetry\SpanEmitter;

/**
 * Registers the Server delivery layer: the by-reference secret resolver and the
 * response classifier configured from `webhooks.server`. The {@see Delivery\DeliveryPipeline}
 * and {@see Jobs\CallWebhookJob} auto-resolve from these plus the Core bindings, so
 * a queued delivery runs with the configured retry/no-retry-4xx policy.
 */
final class ServerServiceProvider extends ServiceProvider
{
    /**
     * Whether the standalone delivery-log migration is registered automatically when
     * persistence is enabled. Disable with self::ignoreMigrations() to publish and
     * manage it in the host app instead.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->app->singleton(SecretResolver::class, EncryptedSecretResolver::class);

        // The engine's own gate lets every queued delivery through: a consumer driving
        // PendingWebhook directly owns the decision to enqueue, and the Server layer keeps
        // no registry of endpoints to re-check it against. The Platform layer rebinds
        // this to the gate that re-reads the subscription (SubscriptionDeliveryGate).
        $this->app->singleton(DeliveryGate::class, OpenDeliveryGate::class);

        $this->app->singleton(ResponseClassifier::class, fn (): ResponseClassifier => new ResponseClassifier(
            retryOn4xx: ! Config::boolean('webhooks.server.no_retry_on_4xx', true),
            retryable4xx: $this->intList('webhooks.server.retryable_4xx', [408, 425, 429]),
        ));

        // The tracing seam defaults to a no-op emitter, so nothing is emitted and no
        // OpenTelemetry SDK is pulled in. A host binds its own SpanEmitter to forward
        // spans to its tracer.
        $this->app->singleton(SpanEmitter::class, NullSpanEmitter::class);
    }

    /**
     * The Server delivery engine boots when its own switch is on OR when the Platform
     * layer is on: Platform fan-out dispatches every delivery through this engine, so
     * enabling the Platform layer forces the Server on even when webhooks.server.enabled
     * is false. When both switches are off the whole layer no-ops — no delivery
     * listeners, standalone persistence, migrations, schedule or telemetry wiring —
     * exactly as the Client layer no-ops while disabled. Exposed so the conditional
     * itself is directly testable.
     */
    public function shouldBoot(): bool
    {
        if (Config::boolean('webhooks.server.enabled', true)) {
            return true;
        }

        return Config::boolean('webhooks.platform.enabled', true);
    }

    public function boot(): void
    {
        if (! $this->shouldBoot()) {
            return;
        }

        // The OpenTelemetry seam is off by default. Only when it is enabled is the
        // span-recording listener wired onto the terminal delivery events; while off,
        // no listener is registered and the bound emitter is never called.
        if (Config::boolean('webhooks.otel.enabled', false)) {
            Event::listen(WebhookAttemptSucceeded::class, [RecordDeliverySpan::class, 'onSucceeded']);
            Event::listen(WebhookAttemptsExhausted::class, [RecordDeliverySpan::class, 'onFinalFailure']);
        }

        // Standalone delivery persistence is off by default: the Platform layer owns
        // the delivery log when it runs, so this only comes on for a send-without-
        // Platform consumer. When on, the subscriber upserts every delivery event
        // into webhook_server_deliveries, its migration is registered, and pruning is
        // scheduled. While off, none of it loads and today's behavior is unchanged.
        if ($this->persistsDeliveries()) {
            Event::subscribe(PersistServerDelivery::class);

            if (self::$runsMigrations) {
                $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/server');
            }

            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('model:prune', ['--model' => [WebhookServerDelivery::class]])->daily();
            });
        }
    }

    /**
     * The single gate the standalone persistence layer hangs off, exposed so the
     * conditional itself is directly testable.
     */
    public function persistsDeliveries(): bool
    {
        return Config::boolean('webhooks.server.persistence.enabled', false);
    }

    /**
     * @param  list<int>  $default
     * @return list<int>
     */
    private function intList(string $key, array $default): array
    {
        return array_values(array_filter(Config::array($key, $default), is_int(...)));
    }
}
