<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

use Pushery\Webhooks\Core\Signing\Exceptions\InvalidMessage;

/**
 * The content a call between two services is signed over: its method, its target and its body.
 *
 * A delivery is signed over its body, and for a delivery that is enough, because the endpoint it goes to acts on the
 * body alone. A call to an API names what it acts on in its address, the hold in `/holds/7/release`, and one secret
 * usually signs every call of a connection. Signed over the body alone, a call that was seen once could be sent again
 * to every other address of the connection until its timestamp runs out.
 *
 * So a call is signed over its method, a space, its target, a line feed and its body:
 *
 *     POST /holds/7/release?notify=1
 *     {"reason":"expired"}
 *
 * The target is the request target as sent: the path and the query, without scheme and host. It holds no whitespace,
 * so the space ends the method and the line feed ends the target, and no part can be moved into another. The method
 * is uppercased, the way a receiving server reads it.
 *
 * A scheme signs this content where it would sign the body of a delivery, with its own id and timestamp around it:
 * Standard Webhooks over `{id}.{timestamp}.{content}`. {@see SignsRequests} signs an outgoing call this way.
 */
final class SignedRequest
{
    /**
     * The content for a call with this method, target and body.
     *
     * @throws InvalidMessage when the method is no HTTP token or the target is not a path from `/` without whitespace
     */
    public static function content(string $method, string $target, string $body): string
    {
        if (preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $method) !== 1) {
            throw InvalidMessage::method($method);
        }

        if (preg_match('/\A\/[^\s\x00-\x1F\x7F]*\z/', $target) !== 1) {
            throw InvalidMessage::target($target);
        }

        return strtoupper($method).' '.$target."\n".$body;
    }
}
