<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

use Webhooks\Client\Verification\InboundVerifier;

/**
 * The outcome of verifying an incoming webhook. Only {@see self::Valid} lets a request
 * through; every other value is a refusal.
 *
 * The first four are what a SIGNATURE SCHEME can produce, and for a scheme they are
 * complete: a pure function of body + headers + secret always reaches a verdict, and every
 * verdict but Valid maps to a 4xx (never a 5xx — a bad signature cannot start passing
 * later, so telling the sender to retry is wrong).
 *
 * {@see self::Undetermined} exists because an {@see InboundVerifier}
 * does I/O, and I/O has an exit a pure function does not: the provider was not asked. It is
 * still a refusal — nothing is stored and nothing is dispatched — but it is the one refusal
 * that says nothing about the delivery, and it is the only one a retry could resolve.
 */
enum VerificationStatus: string
{
    case Valid = 'valid';

    case Invalid = 'invalid';

    case Expired = 'expired';

    case Malformed = 'malformed';

    case Undetermined = 'undetermined';
}
