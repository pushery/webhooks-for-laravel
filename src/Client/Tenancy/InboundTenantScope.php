<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Tenancy;

use Closure;

/**
 * Runs the package's work on a tenant's calls in the host's context for that tenant.
 *
 * Set as a config's `tenant_scope`, beside its `tenant_resolver`, when `webhook_calls` sits under row level security
 * on its `tenant` column. The package then reaches a tenant's call only inside run(): the store and the read-back in
 * the request, the restore of the call and the handler in the job, the outcome it records, and the prune and the
 * payload sweep, which visit every tenant tenants() lists.
 *
 * run() opens a transaction on the package's connection and sets what the policy reads, local to that transaction, so
 * the context ends with it and never carries over to the next transaction a pooled connection serves:
 *
 *     public function run(string $tenant, Closure $work): mixed
 *     {
 *         $db = WebhookConnection::db();
 *
 *         return $db->transaction(function () use ($db, $tenant, $work): mixed {
 *             $db->statement("select set_config('app.workspace_id', ?, true)", [$tenant]);
 *
 *             return $work();
 *         });
 *     }
 *
 * The handler of a call runs inside run() as well, so the transaction lasts as long as the handler does.
 */
interface InboundTenantScope
{
    /**
     * Run the work in the context of the tenant and hand back what it returns.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function run(string $tenant, Closure $work): mixed;

    /**
     * Every tenant whose calls the prune and the payload sweep reach, one run() each, by the key the resolver stores
     * the calls with. An integer key is passed to run() as its string.
     *
     * @return iterable<int|string>
     */
    public function tenants(): iterable;
}
