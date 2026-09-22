<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\Webhooks\Console\PreflightCommand;
use Pushery\Webhooks\Core\Http\HttpTransport;
use Pushery\Webhooks\Core\Signing\Console\Ed25519KeygenCommand;
use Pushery\Webhooks\Core\Signing\Jwks\JwksKeySet;
use Pushery\Webhooks\Core\Ssrf\AddressClassifier;
use Pushery\Webhooks\Core\Ssrf\DefaultSsrfGuard;
use Pushery\Webhooks\Core\Ssrf\HostResolver;
use Pushery\Webhooks\Core\Ssrf\SsrfGuard;
use Pushery\Webhooks\Core\Ssrf\SystemHostResolver;
use Pushery\Webhooks\Support\MergesPackageConfig;

/**
 * Registers the always-on Core layer: the shared SSRF guard and IP-pinned HTTP
 * transport used by both the Server (delivery) and Client (JWKS) layers. The SSRF
 * policy is the single source of truth in `webhooks.core.ssrf` — no layer mirrors
 * it. Signature schemes are plain value objects resolved on demand, so they need
 * no binding here; the JWKS key set is a singleton because it caches fetched keys.
 */
final class CoreServiceProvider extends ServiceProvider
{
    use MergesPackageConfig;

    #[Override]
    public function register(): void
    {
        $this->mergePackageConfig();

        $this->app->singleton(HostResolver::class, SystemHostResolver::class);
        $this->app->singleton(AddressClassifier::class);
        $this->app->singleton(HttpTransport::class);
        $this->app->singleton(JwksKeySet::class);

        // The container arrives as the closure's argument rather than through $this->app, so a
        // long-running worker that resets the container between requests builds the guard from
        // the one it is resolving in, not from the one the provider was registered with.
        $this->app->singleton(SsrfGuard::class, fn (Application $app): SsrfGuard => new DefaultSsrfGuard(
            $app->make(HostResolver::class),
            $app->make(AddressClassifier::class),
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
