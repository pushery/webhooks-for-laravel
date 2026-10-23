<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Tenancy;

use Closure;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Client\Exceptions\WebhookConfigCannotVerify;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Client\WebhookConfig;

/**
 * Reaches a stored call through the tenant scope of its config, and lists the scopes the configs name.
 *
 * @internal
 */
final class TenantScopes
{
    /**
     * Run the work on a call in the scope of its config and tenant, or directly when there is none.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function run(WebhookCall $call, Closure $work): mixed
    {
        return self::runFor($call->source, $call->tenant, $work);
    }

    /**
     * Run the work on the call of a tenant of the named config in that config's scope, or directly when the config
     * sets none or the call has no tenant.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function runFor(string $source, ?string $tenant, Closure $work): mixed
    {
        if ($tenant === null) {
            return $work();
        }

        $scope = self::of($source);

        return $scope instanceof InboundTenantScope ? $scope->run($tenant, $work) : $work();
    }

    /**
     * The scope of the named config, or null when it sets none or is no longer configured.
     */
    public static function of(string $source): ?InboundTenantScope
    {
        try {
            return WebhookConfig::forName($source)->tenantScope();
        } catch (WebhookConfigCannotVerify) {
            // A config removed since its calls were stored leaves them to be reached directly, like the calls of a
            // config without a scope.
            return null;
        }
    }

    /**
     * Every scope the configs name, one per class, for the work that visits each tenant in turn.
     *
     * @return list<InboundTenantScope>
     */
    public static function all(): array
    {
        $scopes = [];

        foreach (Config::array('webhooks.client.configs', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['tenant_scope']) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            $scope = WebhookConfig::forName($entry['name'])->tenantScope();

            if ($scope instanceof InboundTenantScope) {
                $scopes[$scope::class] = $scope;
            }
        }

        return array_values($scopes);
    }
}
