<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * The outcome of verifying an incoming webhook signature. Only {@see self::Valid}
 * lets a request through; every other value maps to a 4xx on the receiving side
 * (never a 5xx — a bad signature can never pass, so telling the sender to retry
 * is wrong).
 */
enum VerificationStatus: string
{
    case Valid = 'valid';

    case Invalid = 'invalid';

    case Expired = 'expired';

    case Malformed = 'malformed';
}
