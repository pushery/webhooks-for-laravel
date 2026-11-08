<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Pushery\Webhooks\Core\Http\Exceptions\ResponseTooLarge;

/**
 * The sink of a {@see GuardedFetch}: it keeps the body up to a limit and refuses the write that
 * would pass it.
 *
 * The refusal is the point, and it is the opposite of what {@see CappedSink} does for a delivery.
 * A write that reports fewer bytes than it was handed makes curl end the transfer with a write
 * error, so a server that sends without end costs the limit and no more, whether or not it named
 * a length. The delivery transport accepts every write instead, because there an oversized answer
 * still belongs to a delivered webhook. A fetch has no use for a body it may not keep.
 *
 * With `$keepHead` the transfer ends at the limit all the same, but the bytes up to it are kept,
 * for a fetch whose caller reads the head of a longer body, as a crawler reads a `robots.txt`.
 *
 * @internal
 */
final class LimitedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $received = 0;

    private bool $overflowed = false;

    private ?ResponseInterface $response = null;

    public function __construct(
        private readonly int $limit,
        private readonly bool $keepHead = false,
    ) {
        $this->stream = Utils::streamFor('');
    }

    public function write(string $string): int
    {
        if ($this->received + strlen($string) > $this->limit) {
            $this->overflowed = true;

            // The transfer ends with this write, so the bytes up to the limit are the last kept.
            if ($this->keepHead) {
                $this->stream->write(substr($string, 0, $this->limit - $this->received));
            }

            // Fewer bytes than curl handed over, so curl ends the transfer here.
            return 0;
        }

        $this->received += strlen($string);

        return $this->stream->write($string);
    }

    /**
     * Refuse a body whose declared length is past the limit, before its first byte arrives. A
     * length that is missing or not a number is left to the writes, which hold the limit anyway.
     *
     * @throws ResponseTooLarge when the declared length is past the limit
     */
    public function refuseDeclaredLength(string $contentLength): void
    {
        $declared = filter_var($contentLength, FILTER_VALIDATE_INT);

        if (is_int($declared) && $declared > $this->limit) {
            $this->overflowed = true;

            throw ResponseTooLarge::over($this->limit);
        }
    }

    /** Whether the body passed the limit, by its declared length or by what arrived. */
    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    /** The bytes kept so far, the head of the body where it passed the limit and `$keepHead` holds. */
    public function kept(): string
    {
        $this->stream->rewind();

        return $this->stream->getContents();
    }

    /**
     * Keep the status and the headers of the response the body belongs to, for a transfer the limit
     * ends in an exception. An informational answer, such as `103 Early Hints`, may come first; the
     * final response follows it before any byte of the body and takes its place.
     */
    public function keepResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /** The response kept, or null while none has arrived. */
    public function response(): ?ResponseInterface
    {
        return $this->response;
    }
}
