<?php

declare(strict_types=1);

namespace Webhooks\Support;

/**
 * A name-based (version 5) UUID: the same namespace and name always yield the same
 * UUID, on every machine and every run. It is the RFC 4122 §4.3 construction — SHA-1
 * of the namespace's 16 bytes followed by the name, with the version and variant bits
 * fixed — so its output is byte-for-byte the value uuid.uuid5() produces in any other
 * language, which the test pins against the canonical python.org DNS vector.
 *
 * The package needs it in exactly one place: the spatie import command derives a stable
 * primary key from (source, spatie-row-id) so a second run of the same import re-derives
 * the same ids and skips what it already wrote, rather than duplicating history. A random
 * UUID could not do that. It is deliberately hand-rolled rather than pulled from a UUID
 * library so the package's `require` list stays as lean as it is (illuminate, guzzle,
 * opis) for one small, well-understood function.
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
