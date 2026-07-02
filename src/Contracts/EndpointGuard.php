<?php

declare(strict_types=1);

namespace Webhooks\Contracts;

use Webhooks\Exceptions\BlockedEndpointException;

/**
 * Validates that a webhook URL is a permitted delivery target. The default
 * implementation refuses private, loopback and cloud-metadata addresses; bind
 * your own to customize the policy.
 */
interface EndpointGuard
{
    /**
     * Validate a webhook URL and return the resolved IP addresses that were
     * checked. The list is empty when the host was deliberately not resolved (an
     * allow-listed host, or with private-network blocking disabled). Callers may
     * pin the outgoing connection to the returned addresses so that the address
     * that was validated is the exact address connected to (defeating DNS rebinding).
     *
     * @return list<string>
     *
     * @throws BlockedEndpointException when the URL is not a permitted target.
     */
    public function validate(string $url): array;
}
