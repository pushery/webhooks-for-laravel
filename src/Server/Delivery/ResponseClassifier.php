<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Delivery;

use JsonException;
use Pushery\Webhooks\Core\Http\Exceptions\NonRetryable;
use Pushery\Webhooks\Core\Http\TransportResponse;

/**
 * Classifies a delivery response into success / retry / final-failure.
 *
 * - 2xx → success.
 * - 5xx → retryable (transient server error).
 * - 4xx → final failure by default; a 400/410 will never pass, so retrying for
 *   hours is waste. The exceptions are the "come back later" codes 408, 425 and
 *   429, which are retried. `retryOn4xx` flips the whole 4xx class to retryable.
 *   A delivery can name problem types of its own: a 4xx whose body is an RFC 9457
 *   problem document of one of them is retried as well.
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

    /**
     * @param  list<string>  $retryableProblemTypes  the problem types this delivery retries a 4xx for
     */
    public function classify(TransportResponse $response, array $retryableProblemTypes = []): Disposition
    {
        $status = $response->status;

        if ($status >= 200 && $status < 300) {
            return Disposition::Succeeded;
        }

        if ($status >= 500) {
            return Disposition::Retryable;
        }

        if ($status >= 400) {
            if ($this->retryOn4xx || in_array($status, $this->retryable4xx ?? self::DEFAULT_RETRYABLE_4XX, true)) {
                return Disposition::Retryable;
            }

            return in_array($this->problemType($response), $retryableProblemTypes, true)
                ? Disposition::Retryable
                : Disposition::FinalFailure;
        }

        return Disposition::FinalFailure;
    }

    /**
     * The `type` of the RFC 9457 problem document a response carries, or null when it carries
     * none: its media type is not `application/problem+json`, its body was cut at the capture
     * limit, or the body is not a JSON object with a string `type`. A problem type is a URI and
     * is compared as written.
     */
    private function problemType(TransportResponse $response): ?string
    {
        if ($response->truncated) {
            return null;
        }

        $mediaTypes = [];

        foreach ($response->headers as $name => $values) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                foreach ($values as $value) {
                    $mediaTypes[] = strtolower(trim(explode(';', $value, 2)[0]));
                }
            }
        }

        if (! in_array('application/problem+json', $mediaTypes, true)) {
            return null;
        }

        try {
            $document = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (is_array($document) && is_string($document['type'] ?? null)) {
            return $document['type'];
        }

        return null;
    }
}
