<?php

declare(strict_types=1);

namespace Webhooks\Server\Delivery;

use Throwable;
use Webhooks\Core\Http\TransportResponse;

/**
 * The result of one delivery attempt run by the {@see DeliveryPipeline}: a
 * disposition plus whichever of the captured response / thrown exception applies.
 * Queue control (retry vs. fail) is the job's concern, not the pipeline's.
 *
 * @internal
 */
final readonly class AttemptOutcome
{
    private function __construct(
        public Disposition $disposition,
        public ?TransportResponse $response = null,
        public ?Throwable $exception = null,
    ) {}

    public static function succeeded(TransportResponse $response): self
    {
        return new self(Disposition::Succeeded, $response);
    }

    public static function retryable(?TransportResponse $response, ?Throwable $exception): self
    {
        return new self(Disposition::Retryable, $response, $exception);
    }

    public static function finalFailure(?TransportResponse $response, ?Throwable $exception): self
    {
        return new self(Disposition::FinalFailure, $response, $exception);
    }
}
