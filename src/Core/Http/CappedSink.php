<?php

declare(strict_types=1);

namespace Webhooks\Core\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * The response sink: it KEEPS at most a bounded prefix of the body and throws the rest
 * away as it arrives, while telling curl every byte was written so the transfer runs to
 * a clean finish.
 *
 * Without it, the transport buffers whatever the endpoint chooses to send — Guzzle's
 * curl handler writes the whole response into a php://temp sink, which spills to disk
 * past 2 MB — and only then trims it to the capture cap. In the Platform layer the
 * destination is TENANT-SUPPLIED through the self-service portal, so "whatever the
 * endpoint chooses to send" is an adversarial quantity: a broken (or hostile) endpoint
 * answering with an endless stream would have a worker writing gigabytes to /tmp for the
 * whole request timeout. Keeping only the prefix bounds the cost to the cap, whatever
 * arrives.
 *
 * It accepts the writes rather than refusing them on purpose: a short write makes curl
 * abort the transfer with a write error, which would turn a large-but-perfectly-good
 * response into a spurious delivery failure. One byte beyond the cap is retained, purely
 * so the reader can tell "exactly the cap" from "more than the cap" and flag truncation.
 *
 * @internal
 */
final class CappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $kept = 0;

    public function __construct(
        private readonly int $cap,
    ) {
        $this->stream = Utils::streamFor('');
    }

    public function write(string $string): int
    {
        // One byte of headroom over the cap: the reader takes the cap and reports
        // truncation when it finds anything after it.
        $room = $this->cap + 1 - $this->kept;

        if ($room > 0) {
            $kept = substr($string, 0, $room);
            $this->stream->write($kept);
            $this->kept += strlen($kept);
        }

        // Report a full write regardless — curl aborts the transfer when the write
        // function reports fewer bytes than it handed over.
        return strlen($string);
    }
}
