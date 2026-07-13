<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * Inbound verification of real Stripe webhook deliveries. Stripe's dialect is
 * exactly {@see StripeStyleScheme}'s — signed content `{timestamp}.{rawBody}`,
 * hex HMAC-SHA256 over the endpoint secret's raw bytes, carried as
 * `t=<unix>,v1=<hex>` — but Stripe sends it in a `Stripe-Signature` header and the
 * replay tolerance matters, so this dedicated adapter pins that header while
 * inheriting the byte-for-byte signing and the tolerance check from the base.
 *
 * Select it per source with a Client config `scheme` => StripeScheme::class.
 * It is never a sending default; {@see StandardWebhooksScheme} is.
 */
final readonly class StripeScheme extends StripeStyleScheme
{
    public const string STRIPE_HEADER = 'Stripe-Signature';

    public function __construct()
    {
        parent::__construct(self::STRIPE_HEADER);
    }
}
