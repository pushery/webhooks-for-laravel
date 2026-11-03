<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * Why {@see GuardedFetch::follow()} ended at a redirect instead of following it. The redirect is
 * then the last station of the chain, and its target is the one the chain did not go to.
 */
enum RedirectStop
{
    /** The chain followed as many redirects as it was allowed. */
    case TooManyRedirects;

    /** The target is a URL the chain had reached before. */
    case Loop;

    /** The target lies outside the {@see RedirectScope} the caller set. */
    case OutOfScope;

    /** The target is plaintext `http` after `https`, which the caller did not allow. */
    case Downgrade;

    /** The target is no `http` or `https` URL with a host. */
    case InvalidTarget;

    /** The SSRF guard refused the target. */
    case Refused;

    /** The target's host resolved to no address. */
    case Unresolvable;
}
