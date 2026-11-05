<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * A fetch ran into its timeout after the server had accepted the connection: no answer within the
 * time, or an answer that had not ended by then.
 *
 * It is a `ConnectionException`, so a `catch (ConnectionException)` written before it still takes
 * it. A caller that answers the two cases differently catches this one first: a server that took
 * the connection and is slow on one URL is still there, while a connection that never came about
 * may mean the server is gone.
 *
 * Both guzzle majors open the text of a failed transfer with curl's errno and message, and curl
 * names the step a timeout struck in. A timeout counts as one after the connection where that
 * message names no step of making it. Guzzle 8 tells its own two timeout exceptions apart by the
 * same words, so the answer is the same under either major.
 */
final class ResponseTimedOut extends ConnectionException
{
    /** How both guzzle majors open the text of a curl timeout, errno 28. */
    private const string TIMEOUT = 'cURL error 28: ';

    /** What curl writes for a timeout while the connection is being made, as guzzle 8 lists it. */
    private const array WHILE_CONNECTING = [
        'connection timed out',
        'connection timeout',
        'connection time-out',
        'resolving timed out',
        'name lookup timed out',
        'proxy connect aborted due to timeout',
        'ssl connection timeout',
    ];

    /**
     * The failure as a fetch reports it: a timeout after the connection as this exception, with
     * the same message and cause, and every other failure as it came.
     */
    public static function refine(Throwable $failure): Throwable
    {
        if ($failure instanceof self || ! $failure instanceof ConnectionException || ! self::struckAfterConnecting($failure->getMessage())) {
            return $failure;
        }

        return new self($failure->getMessage(), 0, $failure->getPrevious());
    }

    /** Whether a message is a curl timeout whose words name no step of making the connection. */
    private static function struckAfterConnecting(string $message): bool
    {
        if (! str_starts_with($message, self::TIMEOUT)) {
            return false;
        }

        // Curl's words end where guzzle appends its reference and the URL, so a URL names nothing.
        $words = substr($message, strlen(self::TIMEOUT));
        $curl = strtolower(strstr($words, ' (see ', true) ?: $words);

        foreach (self::WHILE_CONNECTING as $phrase) {
            if (str_contains($curl, $phrase)) {
                return false;
            }
        }

        return ! str_contains($curl, 'failed to resolve') || ! str_contains($curl, 'timeout');
    }
}
