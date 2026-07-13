<?php

declare(strict_types=1);

namespace Webhooks\Platform\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The full-page self-service portal shell. It hosts the tenant's own endpoint list,
 * the create/edit form and the signing-secret panel, wired together by events so a
 * save refreshes the list and a row can open the form or reveal its secret without a
 * full navigation.
 *
 * The whole page hangs off the manage-webhook-endpoints gate, authorized in mount so
 * an unauthorized tenant is refused before any panel renders. Each panel additionally
 * scopes every query to the acting tenant, so a customer only ever manages the
 * endpoints it owns.
 */
#[Layout('webhooks::self-service.layout')]
final class SelfServicePortalPage extends Component
{
    public function mount(): void
    {
        $this->authorize('manage-webhook-endpoints');
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::self-service.page');
    }
}
