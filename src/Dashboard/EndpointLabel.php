<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard;

/**
 * What the dashboard calls the endpoint a delivery went to: its name, or for an endpoint without
 * one the host of its URL, or its id when even that cannot be read.
 *
 * Never the URL. The dashboard is read by people who may not manage endpoints, and a URL is a
 * place hosts put credentials, in its userinfo or its query. The screens show no URL anywhere,
 * and an accessible name a screen reader reads out discloses it as much as visible text would.
 *
 * @internal
 */
final class EndpointLabel
{
    public static function for(?string $name, ?string $url, int|string $subscriptionId): string
    {
        if ($name !== null && $name !== '') {
            return $name;
        }

        $host = parse_url((string) $url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return (string) $subscriptionId;
    }
}
