<?php

declare(strict_types=1);

namespace Webhooks\Platform\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Support\SubscriptionScope;
use Webhooks\Support\TenantIdentity;

/**
 * Row-level authorization for self-service endpoint management. A self-service query
 * already constrains to the resolved owner, so a foreign row never loads; this policy
 * is the second, defence-in-depth check on the action itself.
 *
 * Every ability is granted only when the subscription belongs to the acting tenant
 * AND — when a host has wired the manage-webhook-endpoints ability — that ability
 * passes. With the ability undefined, tenant ownership alone authorizes, so the layer
 * is usable turnkey while a host can still tighten it. Deletion additionally honours
 * the allow_delete switch.
 */
final class WebhookSubscriptionPolicy
{
    public function view(Authenticatable $user, WebhookSubscription $subscription): bool
    {
        return $this->ownsAndCan($user, $subscription);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->hasManageAbility($user);
    }

    public function update(Authenticatable $user, WebhookSubscription $subscription): bool
    {
        return $this->ownsAndCan($user, $subscription);
    }

    public function delete(Authenticatable $user, WebhookSubscription $subscription): bool
    {
        return Config::boolean('webhooks.platform.self_service.allow_delete', true)
            && $this->ownsAndCan($user, $subscription);
    }

    public function rotateSecret(Authenticatable $user, WebhookSubscription $subscription): bool
    {
        return $this->ownsAndCan($user, $subscription);
    }

    private function ownsAndCan(Authenticatable $user, WebhookSubscription $subscription): bool
    {
        return $this->ownedByCurrentTenant($subscription) && $this->hasManageAbility($user);
    }

    private function ownedByCurrentTenant(WebhookSubscription $subscription): bool
    {
        $owner = SubscriptionScope::currentOwner();

        return $owner instanceof TenantIdentity && $owner->owns($subscription->owner_type, $subscription->owner_id);
    }

    private function hasManageAbility(Authenticatable $user): bool
    {
        if (! Gate::has('manage-webhook-endpoints')) {
            return true;
        }

        return Gate::forUser($user)->allows('manage-webhook-endpoints');
    }
}
