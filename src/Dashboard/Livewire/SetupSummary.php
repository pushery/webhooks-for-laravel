<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Webhooks\Dashboard\DashboardScope;
use Webhooks\Models\WebhookSubscription;

/**
 * The setup / endpoint-health summary: how many endpoints the acting tenant has
 * registered and how many are active. It doubles as the empty-onboarding hint when
 * a tenant has registered nothing yet.
 */
#[Lazy]
final class SetupSummary extends Component
{
    /**
     * @return array{total: int, active: int, disabled: int}
     */
    #[Computed]
    public function summary(): array
    {
        [$ownerSql, $ownerBindings] = DashboardScope::current()->condition();

        $total = WebhookSubscription::query()
            ->whereRaw($ownerSql, $ownerBindings)
            ->count();
        $active = WebhookSubscription::query()
            ->whereRaw($ownerSql, $ownerBindings)
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'disabled' => $total - $active,
        ];
    }

    public function placeholder(): View
    {
        return ViewFactory::make('webhooks::dashboard.placeholders.panel');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::dashboard.livewire.setup-summary');
    }
}
