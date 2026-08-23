<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard;

/**
 * Answers which zone the dashboard should render its timestamps in, for the reader of THIS
 * request.
 *
 * Point `webhooks.dashboard.timezone` at an implementation when one fixed zone is not enough —
 * a multi-tenant back-office where the answer is a user preference falling back to a tenant
 * setting, which is exactly the case a single config string cannot express.
 *
 * Return null to leave the value in the application zone, which is what an unconfigured
 * dashboard does. Returning null is a real answer here, not a failure: a reader with no
 * preference should see the same thing every other reader sees.
 */
interface DashboardTimezoneResolver
{
    public function timezone(): ?string;
}
