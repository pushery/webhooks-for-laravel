<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Delivery;

use InvalidArgumentException;

/**
 * The value of the `Authorization` header a delivery's bearer token is sent in.
 *
 * A token is one or more visible ASCII characters. A space, a tab, a line break or a control character would end the
 * header or start another one, so such a token is refused before anything is sent, and the message never repeats it.
 *
 * @internal
 */
final class BearerToken
{
    public static function header(string $token): string
    {
        if (preg_match('/\A[\x21-\x7E]+\z/', $token) !== 1) {
            throw new InvalidArgumentException('A bearer token is one or more visible ASCII characters, with no space, tab, line break or control character.');
        }

        return 'Bearer '.$token;
    }
}
