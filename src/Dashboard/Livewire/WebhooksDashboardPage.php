<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pushery\Webhooks\Dashboard\WindowResolver;

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
