<?php

declare(strict_types=1);

namespace Webhooks\Server\Delivery;

use Webhooks\Core\Http\TransportResponse;

/**
 * Classifies a delivery response into success / retry / final-failure.
 *
 * - 2xx → success.
 * - 5xx → retryable (transient server error).
 * - 4xx → final failure by default; a 400/410 will never pass, so retrying for
 *   hours is waste. The exceptions are the "come back later" codes 408, 425 and
 *   429, which ARE retried. `retryOn4xx` flips the whole 4xx class to retryable.
 * - 3xx and any other non-2xx → final failure. Redirects are never followed
 *   (an open redirect is an SSRF vector), so a 3xx is a misconfigured endpoint.
 *
 * A thrown {@see NonRetryable} (e.g. a blocked
 * destination) is a final failure handled by the job directly, not here.
 *
 * @internal
 */
final readonly class ResponseClassifier
{
    /** @var list<int> */
    private const array DEFAULT_RETRYABLE_4XX = [408, 425, 429];

    /**
     * @param  list<int>|null  $retryable4xx
     */
    public function __construct(
        private bool $retryOn4xx = false,
        private ?array $retryable4xx = null,
    ) {}

    public function classify(TransportResponse $response): Disposition
    {
        $status = $response->status;

        if ($status >= 200 && $status < 300) {
            return Disposition::Succeeded;
        }

        if ($status >= 500) {
            return Disposition::Retryable;
        }

        if ($status >= 400) {
            return $this->retryOn4xx || in_array($status, $this->retryable4xx ?? self::DEFAULT_RETRYABLE_4XX, true)
                ? Disposition::Retryable
                : Disposition::FinalFailure;
        }

        return Disposition::FinalFailure;
    }
}
