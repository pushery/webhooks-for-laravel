<?php

declare(strict_types=1);

namespace Webhooks\Core\Http\Exceptions;

use Throwable;

/**
 * Marks a failure that must NOT be retried. The Server delivery job treats a
 * thrown {@see Throwable} implementing this as a final, non-retryable failure —
 * retrying it would only waste attempts (the destination can never become valid
 * within the delivery's lifetime).
 */
interface NonRetryable extends Throwable {}
