<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

/**
 * A name-based (version 5) UUID: the same namespace and name always yield the same
 * UUID, on every machine and every run. It is the RFC 4122 §4.3 construction — SHA-1
 * of the namespace's 16 bytes followed by the name, with the version and variant bits
 * fixed — so its output is byte-for-byte the value uuid.uuid5() produces in any other
 * language, which the test pins against the canonical python.org DNS vector.
 *
 * The package needs it in exactly one place: the backlog import command derives a stable
 * primary key from (source, source-row-id) so a second run of the same import re-derives
 * the same ids and skips what it already wrote, rather than duplicating history. A random
 * UUID could not do that.
 *
 * The stated reason for hand-rolling it used to be false, and it is worth replacing rather
 * than deleting. It read: hand-rolled "so the package's `require` list stays as lean as it is
 * (illuminate, guzzle, opis)". Both halves fail on the first check. `ramsey/uuid ^4.7` is a hard
 * require of `laravel/framework`, so the library is already in every install of this package and
 * using it would add nothing to the tree. And `require` names no `illuminate/*` at all — that is
 * a deliberate decision this repo documents at length, so the parenthetical contradicted it while
 * omitting two of the five packages actually listed.
 *
 * The real reason it stays: using the library would mean declaring `ramsey/uuid` in `require`,
 * because a package declares what it uses directly rather than borrowing a transitive. That is a
 * second constraint to carry across the next framework major, for one twenty-line function whose
 * output is fixed by RFC 4122 and cannot drift. The trade is worth making the other way, and it
 * is only defensible because the equivalence is checked: the test pins this against
 * `Ramsey\Uuid\Uuid::uuid5()` as well as against the canonical python.org vector, so the day the
 * two disagree is the day the suite says so.
 *
 * @internal
 */
final class DeterministicUuid
{
    /**
     * The version-5 UUID for a namespace (itself a UUID string) and a name.
     */
    public static function v5(string $namespace, string $name): string
    {
        // The namespace's 16 raw bytes, hashed together with the name. sha1() over binary
        // returns the 40-hex-character digest RFC 4122 slices the UUID fields out of.
        $hash = sha1(hex2bin(str_replace('-', '', $namespace)).$name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            // time_low, time_mid — taken verbatim from the digest.
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            // time_hi_and_version: the high nibble is forced to 5 to mark the version.
            hexdec(substr($hash, 12, 4)) & 0x0FFF | 0x5000,
            // clock_seq: the top two bits are forced to 10 for the RFC 4122 variant.
            hexdec(substr($hash, 16, 4)) & 0x3FFF | 0x8000,
            // node — the trailing 12 hex characters of the digest.
            substr($hash, 20, 12),
        );
    }
}
