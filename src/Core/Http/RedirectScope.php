<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * Where {@see GuardedFetch::follow()} may follow a redirect, measured against the host of the URL it
 * started at.
 */
enum RedirectScope
{
    /** Wherever the guard lets a station go. */
    case Anywhere;

    /** The host the chain started at, and no other. */
    case SameHost;

    /**
     * The host the chain started at, the same name with or without a leading `www.`, and every
     * subdomain of that name. `example.com` may lead to `www.example.com` and `shop.example.com`,
     * and back. A name above it never counts: `user.example.net` may not lead to `example.net`,
     * which is where one tenant of a shared domain would reach another. No public suffix list is
     * read, so this rule stays inside the name the chain started at.
     */
    case SameDomain;

    /** Whether a redirect from the chain's first host may lead to `$to`. */
    public function allows(string $from, string $to): bool
    {
        $from = strtolower($from);
        $to = strtolower($to);

        if ($this === self::Anywhere || $from === $to) {
            return true;
        }

        if ($this === self::SameHost) {
            return false;
        }

        $name = str_starts_with($from, 'www.') ? substr($from, 4) : $from;

        return $to === $name || str_ends_with($to, '.'.$name);
    }
}
