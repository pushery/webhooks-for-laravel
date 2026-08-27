<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a configured secret derives no HMAC key at all.
 *
 * Standard Webhooks derives the key by stripping an optional `whsec_` prefix and
 * base64-decoding the rest. A value carrying no base64 characters therefore decodes
 * to ZERO bytes, and HMAC-SHA256 under an empty key is a pure function of bytes the
 * attacker already has: anyone who sees `{id}.{timestamp}.{body}` can compute the
 * signature. The commonest way to reach that state is the bare prefix `whsec_`,
 * which is exactly what `WEBHOOKS_..._SECRET=whsec_${SECRET}` produces when the
 * variable is unset.
 *
 * This is a SENDING-side programmer or configuration error, so it is raised. The
 * receiving side never raises it — an unusable secret there is skipped, because an
 * unverifiable request must answer with a refusal rather than a 500.
 */
final class UnusableSigningSecret extends InvalidArgumentException
{
    public static function derivesNoKey(string $prefix): self
    {
        return new self(
            'A Standard Webhooks secret must base64-decode to at least one byte after the optional '
            ."[{$prefix}] prefix. The configured value decodes to nothing, which would sign every "
            .'delivery with an empty HMAC key — a signature anyone who sees the request can reproduce.'
        );
    }
}
