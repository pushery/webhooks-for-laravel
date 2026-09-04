<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * Every provider in this package that has a register() merges the same shipped configuration,
 * because a host may mount any one layer on its own. This is that merge, in one place, so the
 * call sites cannot drift apart.
 *
 * The sentence above used to be a roster of six layer names, and it was wrong twice: it named
 * the Server layer, which did not merge, and omitted the umbrella provider, which did. The count
 * was right, which is what made it read as maintained. A roster in prose is a list nothing
 * checks, so it is gone — the rule is stated instead, and ProviderConfigMergeTest holds every
 * provider to it by reading the tree rather than a list.
 *
 * The path is resolved once here rather than six times at the call sites. `__DIR__` inside
 * a trait resolves to the directory of the trait file, not of the class using it, so
 * `dirname(__DIR__, 2)` is the package root for every one of them — where the six call
 * sites previously carried two different relative depths between them.
 *
 * The cached-configuration guard is the one from Laravel's own ServiceProvider and has to
 * stay: with a cached config there is nothing to merge into, and writing would silently
 * disagree with what the cache serves.
 *
 * @internal
 *
 * @mixin ServiceProvider
 */
trait MergesPackageConfig
{
    protected function mergePackageConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app->make('config');

        /** @var array<array-key, mixed> $shipped */
        $shipped = require dirname(__DIR__, 2).'/config/webhooks.php';

        /** @var array<array-key, mixed> $published */
        $published = $config->get('webhooks', []);

        $config->set('webhooks', ConfigMerge::tree($shipped, $published));
    }
}
