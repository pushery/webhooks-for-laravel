<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Exceptions;

use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\WebhookManager;
use RuntimeException;

/**
 * A manual test ping was refused because the endpoint has had too many in the last
 * minute ({@see WebhookManager::ping()}).
 *
 * Refused rather than deferred, which is the opposite of what the delivery rate limit
 * does with an ordinary event — and deliberately so. A real event deferred by two minutes
 * still arrives and still means what it meant; a TEST ping deferred by two minutes has
 * already failed at the only thing it was for, which is telling someone watching the
 * screen right now whether the endpoint answers.
 *
 * It carries the wait so a caller can say when to try again rather than only that it
 * refused.
 */
final class TestPingThrottled extends RuntimeException
{
    public function __construct(
        public readonly WebhookSubscription $subscription,
        public readonly int $secondsUntilAvailable,
    ) {
        parent::__construct(sprintf(
            'Endpoint %d has reached its test-ping allowance; %d second(s) until the next one.',
            $subscription->id,
            $secondsUntilAvailable,
        ));
    }
}
