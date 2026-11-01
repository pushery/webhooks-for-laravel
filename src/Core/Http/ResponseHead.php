<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The head of the response a {@see GuardedFetch::lead()} asks for: it keeps the status and the
 * headers as they arrive and ends the transfer before the first byte of the body.
 *
 * Handed to guzzle as `on_headers`. An exception thrown there makes curl end the transfer with a
 * write error, and the head kept here is the answer the lead hands back.
 *
 * @internal
 */
final class ResponseHead
{
    private ?ResponseInterface $response = null;

    /**
     * Keep the head of a final response and end the transfer. An informational one, such as
     * `103 Early Hints`, comes ahead of the response it belongs to and is passed over.
     *
     * @throws RuntimeException once the head of a final response is kept, which ends the transfer
     */
    public function keep(ResponseInterface $response): void
    {
        if ($response->getStatusCode() < 200) {
            return;
        }

        $this->response = $response;

        throw new RuntimeException('The transfer ends with the head of the response.');
    }

    /** The head kept, or null while no final response has arrived. */
    public function response(): ?ResponseInterface
    {
        return $this->response;
    }
}
