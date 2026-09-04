<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pushery\Webhooks\Dashboard\Livewire\Concerns\InteractsWithDashboard;
use Pushery\Webhooks\Dashboard\WindowResolver;
use Pushery\Webhooks\Support\ScheduleCadence;

/**
 * The full-page dashboard shell. It hosts the panels and owns the two page-level
 * controls — the tab (Overview / Webhooks / Queue / Documentation) and the window
 * (24h / 7d / 30d). Tabs are plain links driven by wire:navigate for an SPA feel
 * without a client router; the window is part of each panel's key, so changing it
 * remounts the panels that take one and they come up on the new window without a
 * full navigation.
 *
 * That is deliberately NOT a broadcast. An event cannot reach a panel that is still
 * lazy — Livewire's client drops it — and the panel would then resolve on the window
 * frozen into its placeholder and never leave it. The key carries the window instead,
 * because a key is read at render time and an event is not. See the comment in the
 * page view for the full chain.
 *
 * The remount the key causes is safe because the panels bundle their lazy loads, and that pairing
 * is the load-bearing part. Livewire isolates lazy loads by default: each `#[Lazy]` child fires its
 * own `__lazyLoad` request and each response morphs this shared parent. Six of those race on first
 * paint, and on a window switch four of them arrive while the components they name are being torn
 * down and replaced — which surfaces as `Public method [__lazyLoad] not found`: a request landing
 * on a snapshot a sibling's response has already re-rendered. Every panel therefore carries
 * `#[Lazy(isolate: false)]`, so the whole set resolves in one request against one consistent set of
 * snapshots.
 *
 * The obvious alternative — take the window out of the keys so nothing remounts — trades a
 * loud, rare error for a quiet, permanent one: the panel resolves on the window frozen into
 * its placeholder, the header reads 7d, the panel counts 24h, and nothing reports it. The
 * race is worth removing; the remount is not.
 */
#[Layout('webhooks::dashboard.layout')]
final class WebhooksDashboardPage extends Component
{
    use InteractsWithDashboard;

    public const array TABS = ['overview', 'webhooks', 'queue', 'documentation'];

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $window = '24h';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }

        if (! in_array($this->window, $this->windows(), true)) {
            // Fall back to the FIRST configured window, not a hardcoded '24h': a host may narrow
            // dashboard.windows to a set that omits 24h, and defaulting to an un-offered window
            // renders a range no button selects and disagrees with what the JSON API serves.
            $this->window = $this->windows()[0];
        }
    }

    /**
     * Switch the active window. Every panel that takes one carries it in its key, so the
     * re-render this triggers remounts them on the new window — no event is needed, and
     * an event would not have reached a panel that is still lazy anyway.
     */
    public function selectWindow(string $window): void
    {
        if (! in_array($window, $this->windows(), true)) {
            return;
        }

        $this->window = $window;
    }

    /**
     * Screen a bound write the same way {@see self::selectWindow()} screens a called one.
     *
     * mount() is not enough, and this is why the hook exists rather than being tidy. `$window`
     * carries `#[Url]`, so it is client input, and mount() runs once. The page view binds it with
     * `wire:model.live`, so every later round trip writes the property directly — with nothing
     * between a hand-edited request and a window the host never offered. That reaches
     * WindowResolver, the panel keys, and the range the panels then count over.
     *
     * A rejected value falls back to the first CONFIGURED window rather than a literal '24h',
     * for the reason mount() gives: a host may narrow dashboard.windows to a set without it.
     */
    public function updatedWindow(string $value): void
    {
        if (! in_array($value, $this->windows(), true)) {
            $this->window = $this->windows()[0];
        }
    }

    /**
     * The selectable window tokens — the same configured, resolver-backed set the JSON
     * metrics endpoint validates against, so page and API always agree on what a window
     * may be.
     *
     * @return non-empty-list<string>
     */
    public function windows(): array
    {
        return WindowResolver::allowed();
    }

    /**
     * How far behind the rollup is, in whole minutes, or null when it is level.
     *
     * The counts on this page are summed from a materialized rollup that only
     * `webhooks:refresh-metrics` advances; the latency percentiles beside them are computed live.
     * With the refresh stopped, frozen counts sit next to current percentiles that make them look
     * plausible, and nothing on the screen says which is which.
     *
     * Rounded to minutes because that is the resolution a reader acts on, and floored at the
     * configured cadence: a rollup one cadence behind is a rollup between two runs, not a broken
     * one. Twice the cadence is the first number that means something went wrong.
     */
    public function rollupLagMinutes(): ?int
    {
        $lag = $this->metricsFor($this->window)->rollupLagSeconds();

        if ($lag === null) {
            return null;
        }

        $minutes = intdiv($lag, 60);

        return $minutes >= 2 * ScheduleCadence::minutesFor(
            Config::string('webhooks.dashboard.metrics.refresh', 'everyFiveMinutes'),
        ) ? $minutes : null;
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.page', [
            'tabs' => self::TABS,
            'windows' => $this->windows(),
        ]);
    }
}
