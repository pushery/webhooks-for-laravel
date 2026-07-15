<?php

declare(strict_types=1);

namespace Webhooks\Core;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;
use Webhooks\Console\PreflightCommand;
use Webhooks\Core\Http\HttpTransport;
use Webhooks\Core\Signing\Console\Ed25519KeygenCommand;
use Webhooks\Core\Signing\Jwks\JwksKeySet;
use Webhooks\Core\Ssrf\AddressClassifier;
use Webhooks\Core\Ssrf\DefaultSsrfGuard;
use Webhooks\Core\Ssrf\HostResolver;
use Webhooks\Core\Ssrf\SsrfGuard;
use Webhooks\Core\Ssrf\SystemHostResolver;

/**
 * Registers the always-on Core layer: the shared SSRF guard and IP-pinned HTTP
 * transport used by both the Server (delivery) and Client (JWKS) layers. The SSRF
 * policy is the single source of truth in `webhooks.core.ssrf` — no layer mirrors
 * it. Signature schemes are plain value objects resolved on demand, so they need
 * no binding here; the JWKS key set is a singleton because it caches fetched keys.
 */
final class CoreServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/webhooks.php', 'webhooks');

        $this->app->singleton(HostResolver::class, SystemHostResolver::class);
        $this->app->singleton(AddressClassifier::class);
        $this->app->singleton(HttpTransport::class);
        $this->app->singleton(JwksKeySet::class);

        $this->app->singleton(SsrfGuard::class, fn (): SsrfGuard => new DefaultSsrfGuard(
            $this->app->make(HostResolver::class),
            $this->app->make(AddressClassifier::class),
            Config::boolean('webhooks.core.ssrf.https_only', true),
            Config::boolean('webhooks.core.ssrf.block_private_networks', true),
            $this->stringList('webhooks.core.ssrf.allowed_hosts'),
            $this->stringList('webhooks.core.ssrf.blocked_hosts'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Ed25519KeygenCommand::class, PreflightCommand::class]);
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        return array_values(array_filter(Config::array($key, []), is_string(...)));
    }
}
