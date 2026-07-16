<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Webhooks\Dashboard\WindowResolver;

/**
 * The full-page dashboard shell. It hosts the panels and owns the two page-level
 * controls — the tab (Overview / Webhooks / Queue / Documentation) and the window
 * (24h / 7d / 30d). Tabs are plain links driven by wire:navigate for an SPA feel
 * without a client router; the window change is broadcast to the panels so each
 * recomputes its own metrics without a full navigation.
 */
#[Layout('webhooks::dashboard.layout')]
final class WebhooksDashboardPage extends Component
{
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
     * Switch the active window and tell every panel to recompute for it.
     */
    public function selectWindow(string $window): void
    {
        if (! in_array($window, $this->windows(), true)) {
            return;
        }

        $this->window = $window;
        $this->dispatch('dashboard-window-changed', window: $window);
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

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.page', [
            'tabs' => self::TABS,
            'windows' => $this->windows(),
        ]);
    }
}
