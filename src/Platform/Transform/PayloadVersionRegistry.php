<?php

declare(strict_types=1);

namespace Webhooks\Platform\Transform;

use Illuminate\Support\Facades\Config;

/**
 * Maps a payload version id to its default transform rules, declared once under
 * webhooks.platform.payload_versioning.versions. An endpoint that only names a
 * version (and carries no explicit transform of its own) inherits that version's
 * rules, so a shared shape is defined in one place rather than copied per endpoint.
 *
 * @internal
 */
final class PayloadVersionRegistry
{
    /**
     * The default rules for a version, or null when the version is unknown or names
     * no rules (a version may exist purely to stamp its id with no field changes).
     *
     * @return array<array-key, mixed>|null
     */
    public function rulesFor(?string $version): ?array
    {
        if ($version === null) {
            return null;
        }

        /** @var array<string, mixed> $versions */
        $versions = Config::array('webhooks.platform.payload_versioning.versions', []);

        $rules = $versions[$version] ?? null;

        return is_array($rules) ? $rules : null;
    }
}
