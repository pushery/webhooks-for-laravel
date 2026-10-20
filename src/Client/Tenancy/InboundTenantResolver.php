<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Tenancy;

use Illuminate\Http\Request;
use Pushery\Webhooks\Client\WebhookConfig;

/**
 * Names the tenant an inbound request is addressed to, and the secrets that sign for it.
 *
 * Set as a config's `tenant_resolver` for a producer that signs each tenant's deliveries with that tenant's own
 * secret, behind one config and one route with the tenant in its path. The resolver is asked before the signature is
 * checked, so it must not trust the request beyond naming the tenant: the secrets it returns decide whether the
 * delivery is authentic.
 *
 * A null answer is refused like a delivery whose signature fails: nothing is stored, nothing is dispatched, and the
 * config's `invalid_status` answers. A resolver that throws, a database that does not answer, is refused like a
 * verification that could not complete, with `undetermined_status` where one is set.
 */
interface InboundTenantResolver
{
    public function resolve(Request $request, WebhookConfig $config): ?InboundTenant;
}
