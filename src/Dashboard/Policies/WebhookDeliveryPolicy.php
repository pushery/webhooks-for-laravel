<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Webhooks\Dashboard\DashboardScope;
use Webhooks\Models\WebhookDelivery;

/**
 * Row-level authorization for dashboard actions. The panels already scope every
 * query by the resolved owner, so a cross-tenant row never loads; this policy is
 * the second, defence-in-depth check on the action itself.
 *
 * Redelivery is allowed when the delivery belongs to the acting tenant AND — when a
 * host has defined a finer-grained 'webhooks.manage' ability — that ability passes.
 * With no such ability defined, tenant ownership alone authorizes, so the dashboard
 * is usable turnkey while a host can still tighten it.
 */
final class WebhookDeliveryPolicy
{
    public function redeliver(Authenticatable $user, WebhookDelivery $delivery): bool
    {
        return DashboardScope::currentOwner()->owns($delivery->owner_type, $delivery->owner_id)
            && $this->hasManageAbility($user);
    }

    private function hasManageAbility(Authenticatable $user): bool
    {
        if (! Gate::has('webhooks.manage')) {
            return true;
        }

        return Gate::forUser($user)->allows('webhooks.manage');
    }
}
