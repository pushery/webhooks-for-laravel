<?php

declare(strict_types=1);

namespace Webhooks\Dashboard;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Override;
use Webhooks\Dashboard\Console\RefreshMetricsCommand;
use Webhooks\Dashboard\Events\WebhookRedeliveryRequested;
use Webhooks\Dashboard\Listeners\RedeliverWebhookListener;
use Webhooks\Dashboard\Livewire\DeliveriesTable;
use Webhooks\Dashboard\Livewire\DeliveryDetailDrawer;
use Webhooks\Dashboard\Livewire\HourlyActivityChart;
use Webhooks\Dashboard\Livewire\KpiCards;
use Webhooks\Dashboard\Livewire\LatencyPanel;
use Webhooks\Dashboard\Livewire\RecentQueue;
use Webhooks\Dashboard\Livewire\SetupSummary;
use Webhooks\Dashboard\Livewire\TopEvents;
use Webhooks\Dashboard\Livewire\WebhooksDashboardPage;
use Webhooks\Dashboard\Policies\WebhookDeliveryPolicy;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Support\PlatformRequirement;
use Webhooks\Support\ScheduleCadence;

/**
 * Boots the folded-in, customer-facing observability read model — the hourly
 * materialized view, the webhooks:refresh-metrics command and its schedule — but
 * ONLY when webhooks.dashboard.enabled. This provider is NOT auto-registered
 * (absent from composer extra.laravel.providers): register it in a host app to
 * expose the dashboard, so a send-only or API-only consumer never pays for it.
 *
 * The Livewire/WireKit presentation panels sit on top of this read model as an
 * optional layer, which is why livewire/livewire and pushery/wirekit remain
 * Composer suggestions rather than hard requirements.
 */
final class WebhooksDashboardServiceProvider extends ServiceProvider
{
    /**
     * Whether the bundled materialized-view migration is registered automatically.
     * Disable with self::ignoreMigrations() to publish and manage it in the host app.
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/webhooks.php', 'webhooks');
    }

    /**
     * The single gate the whole layer hangs off, exposed so the conditional itself
     * is directly testable.
     */
    public function shouldBoot(): bool
    {
        return Config::boolean('webhooks.dashboard.enabled', false);
    }

    public function boot(): void
    {
        if (! $this->shouldBoot()) {
            return;
        }

        // The dashboard's whole read model — the delivery log AND the materialized view
        // defined over it — belongs to the Platform layer. With Platform off, none of it
        // is migrated, so refuse to boot a UI that could only ever throw raw SQL errors.
        // A host that owns its own delivery-log model (dashboard.source_model) has taken
        // that table over and is left alone.
        if ($this->readsThePlatformLog()) {
            PlatformRequirement::ensure(
                'dashboard',
                'webhooks.dashboard.enabled',
                'reads the delivery log (webhook_deliveries)',
            );
        }

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/dashboard');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! Config::boolean('webhooks.schedule.enabled', true)) {
                return;
            }

            $this->scheduleRefresh($schedule);
        });

        $this->registerAuthorization();
        $this->registerRedelivery();
        $this->registerPanels();
        $this->loadRoutesFrom(__DIR__.'/../../routes/dashboard.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshMetricsCommand::class]);
            $this->registerPublishing();
        }
    }

    /**
     * Whether the dashboard still reads the Platform layer's own delivery log — i.e. the
     * host has NOT pointed dashboard.source_model at a log model it owns and migrates
     * itself, which is the documented way to run the dashboard without Platform.
     */
    private function readsThePlatformLog(): bool
    {
        return Config::string('webhooks.dashboard.source_model', WebhookDelivery::class) === WebhookDelivery::class;
    }

    /**
     * The view gate the whole page hangs off, plus the row-level action policy. The
     * gate is fail-CLOSED: with no host 'webhooks.view' ability defined it DENIES, so
     * registering the dashboard never silently exposes the operator surface to every
     * authenticated user. A host grants access by defining 'webhooks.view'.
     */
    private function registerAuthorization(): void
    {
        Gate::define('view-webhook-dashboard', static function (Authenticatable $user): bool {
            // Fail CLOSED: a host that registers the dashboard but never defines the 'webhooks.view'
            // ability must NOT silently expose the operator dashboard to every authenticated user.
            // With no ability defined, deny — the host grants access by defining 'webhooks.view'
            // (see the README's dashboard authorization section).
            if (! Gate::has('webhooks.view')) {
                return false;
            }

            return Gate::forUser($user)->allows('webhooks.view');
        });

        Gate::policy(WebhookDelivery::class, WebhookDeliveryPolicy::class);
    }

    /**
     * Wire the redelivery request event to the listener that hands the replay back
     * to the core engine, so a panel only ever announces intent.
     */
    private function registerRedelivery(): void
    {
        EventDispatcher::listen(WebhookRedeliveryRequested::class, RedeliverWebhookListener::class);
    }

    /**
     * Register the class-based Livewire panels under a stable, dotted alias each, so
     * a host page can also embed a single panel by name.
     */
    private function registerPanels(): void
    {
        Livewire::component('webhooks.dashboard.page', WebhooksDashboardPage::class);
        Livewire::component('webhooks.dashboard.kpi-cards', KpiCards::class);
        Livewire::component('webhooks.dashboard.hourly-activity-chart', HourlyActivityChart::class);
        Livewire::component('webhooks.dashboard.latency-panel', LatencyPanel::class);
        Livewire::component('webhooks.dashboard.recent-queue', RecentQueue::class);
        Livewire::component('webhooks.dashboard.top-events', TopEvents::class);
        Livewire::component('webhooks.dashboard.setup-summary', SetupSummary::class);
        Livewire::component('webhooks.dashboard.deliveries-table', DeliveriesTable::class);
        Livewire::component('webhooks.dashboard.delivery-detail-drawer', DeliveryDetailDrawer::class);
    }

    /**
     * The dashboard Blade views are publishable on their own tag, so a host on a
     * different UI kit can hand-edit them while keeping the rest of the package.
     */
    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../../resources/views/dashboard' => resource_path('views/vendor/webhooks/dashboard'),
        ], 'webhooks-dashboard-views');
    }

    /**
     * Schedule the metrics refresh non-overlapping on the configured cadence. An
     * unknown token falls back to the five-minute default rather than silently never
     * running.
     */
    private function scheduleRefresh(Schedule $schedule): void
    {
        $event = $schedule->command('webhooks:refresh-metrics')->withoutOverlapping();

        ScheduleCadence::apply(
            $event,
            Config::string('webhooks.dashboard.metrics.refresh', 'everyFiveMinutes'),
            'everyFiveMinutes',
        );
    }
}
