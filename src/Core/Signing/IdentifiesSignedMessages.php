<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

use Pushery\Webhooks\Client\Http\VerifySignedRequest;

/**
 * A signature scheme that names the message a signature it accepted covers, so that a receiver can refuse the same
 * message when it arrives a second time.
 *
 * {@see VerifySignedRequest} asks it once a call has verified, and refuses a later call with the same key for the same
 * config and tenant while the signature could still verify. The key comes from what the signature covers and from
 * nothing else: a header the signature leaves open is the sender's to choose, and whoever resends a captured call
 * could write a new value there.
 *
 * The schemes that sign no timestamp do not implement it. A call signed that way verifies at any time, so no key held
 * for a while can refuse it. A scheme of your own implements it to take part.
 */
interface IdentifiesSignedMessages
{
    /**
     * The key of the message the headers sign over the content, or null when the headers carry none.
     *
     * Asked only after {@see SignatureScheme::verify()} accepted the same content and headers. Two messages share a key
     * only when the signature covers the same values in both.
     */
    public function signedMessageKey(string $signedContent, SignatureHeaders $headers): ?string;
}
