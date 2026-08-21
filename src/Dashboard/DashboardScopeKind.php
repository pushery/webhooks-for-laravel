<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard;

/**
 * Which of the three dashboard scopes a {@see DashboardTenant} is.
 *
 * It exists because two of them — global and all-tenants — carry NO tenant identity, so the
 * scope can no longer be derived from "is the identity null". Deriving it that way is what
 * made "the owner-less rows" and "every row" indistinguishable in code, and those two differ
 * by a permission level.
 *
 * @internal
 */
enum DashboardScopeKind
{
    /** One tenant, matched on the whole owner morph pair. */
    case Tenant;

    /** The owner-less rows only — an operator's own global endpoints. */
    case Global;

    /** Every row, whoever owns it — the cross-tenant support console. */
    case AllTenants;
}
