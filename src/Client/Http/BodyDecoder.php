<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Http;

use Pushery\Webhooks\Client\PayloadFormat;
use Pushery\Webhooks\Core\Payload\PayloadSanitizer;

/**
 * Reads the bytes of an inbound delivery into an array, and reports which way it read them.
 *
 * Two rules carry this, and the order between them is the whole design:
 *
 * 1. **JSON is attempted first, always, whatever the request declared.** A declared content
 *    type is not evidence. Symfony stamps `application/x-www-form-urlencoded` onto any request
 *    built without one, and real producers send JSON under a wrong type or none at all. A
 *    decoder that believed the header would hand a JSON document to `parse_str` and get one
 *    nonsense key back, turning deliveries that read correctly today into deliveries that do
 *    not. Reading JSON therefore never depends on what the producer claimed.
 * 2. **The declared type is only ever permission to try the form decoder.** `parse_str` cannot
 *    fail: it reads `not json at all` as a single empty-valued key and reports success. So it
 *    runs when the producer said the body is a form, and never as a guess.
 *
 * `multipart/*` is the one type read as a refusal rather than as permission, and it is refused
 * only after rule 1 has had the body — so a JSON envelope mislabeled `multipart/form-data` is
 * still read, exactly as rule 1 promises.
 *
 * What neither rule reads stays empty and is reported as {@see PayloadFormat::Unreadable},
 * because the alternative — an empty array indistinguishable from a genuinely empty body — is
 * what lets a signature-verified delivery be acknowledged and lost.
 *
 * The same reasoning decides where the boundary between `None` and `Unreadable` sits, and it is
 * the sharper of the two: `None` is a positive claim that the producer sent nothing, so it must
 * never be reached by a delivery that did.
 *
 * @internal
 */
final class BodyDecoder
{
    private const string FORM_MEDIA_TYPE = 'application/x-www-form-urlencoded';

    /**
     * Whitespace, minus the NUL byte that {@see trim()} strips by default. A body of NUL bytes
     * is bytes: reporting it as `None` would claim the producer sent nothing.
     */
    private const string BLANK = " \t\n\r\x0B";

    /**
     * The format the body was read as, and the fields it yielded — NUL-scrubbed.
     *
     * The scrub lives here rather than at the point of storage because both copies of the
     * payload start here: the row the package writes, and the envelope a handler receives.
     * It used to run only before the package's own insert, so the package had cleared a wall
     * for itself and left it standing for every consumer — a handler writing the envelope
     * payload into a jsonb column of its own hit the refusal the package already knew about
     * ({@see PayloadSanitizer} for why jsonb refuses it at all).
     *
     * ⚠️ THE DECODED ARRAY IS SCRUBBED, NEVER THE RAW BODY, and the difference is a false claim
     * rather than a nicety. `BLANK` below deliberately excludes NUL so that a body of NUL bytes
     * is reported as unread rather than as `None`; scrubbing the bytes first would empty such a
     * body and produce exactly the "the producer sent nothing" claim that exclusion exists to
     * prevent. Scrubbing after the reading also leaves the format decision, the stored raw body
     * and its SHA-256 untouched, so the exact received bytes stay beside the cleaned view.
     *
     * @return array{0: PayloadFormat, 1: array<array-key, mixed>}
     */
    public static function decode(string $rawBody, ?string $contentType): array
    {
        [$format, $payload] = self::read($rawBody, $contentType);

        return [$format, PayloadSanitizer::scrub($payload)];
    }

    /**
     * The reading itself: which format the body is, and what it yields verbatim.
     *
     * @return array{0: PayloadFormat, 1: array<array-key, mixed>}
     */
    private static function read(string $rawBody, ?string $contentType): array
    {
        // Rule 1 runs first and unconditionally, including ahead of the multipart refusal
        // below: a JSON body that reaches this decoder is read, whatever it was labeled.
        $decoded = json_decode($rawBody, true);

        if (is_array($decoded)) {
            return [PayloadFormat::Json, $decoded];
        }

        $mediaType = self::mediaType($contentType);

        // Multipart is refused before the body is measured, and the ORDER against the empty
        // check below is the point. PHP consumes a multipart POST into $_POST and $_FILES
        // before any middleware can capture it, so the raw body usually arrives here empty —
        // and reading that emptiness as `None` would state that the producer sent nothing about
        // a delivery that carried fields. That is a confident false claim, which is worse than
        // the silence this class was written to end. Refusing it says the true thing instead:
        // the package does not read multipart, whether or not the bytes survived.
        if ($mediaType !== null && str_starts_with($mediaType, 'multipart/')) {
            return [PayloadFormat::Unreadable, []];
        }

        if (trim($rawBody, self::BLANK) === '') {
            return [PayloadFormat::None, []];
        }

        // A form is name=value pairs. Without a single `=` anywhere, `parse_str` would invent
        // one key from the whole body and report success — so a body that cannot be a form is
        // refused before it can become a fabricated payload, however it was declared.
        if ($mediaType !== self::FORM_MEDIA_TYPE || ! str_contains($rawBody, '=')) {
            return [PayloadFormat::Unreadable, []];
        }

        return self::form($rawBody);
    }

    /**
     * Read a declared form body, refusing anything PHP only partly read.
     *
     * `parse_str` reports success either way, so the two ways it loses fields are asked about
     * directly. Over `max_input_vars` it keeps the first N and RAISES a warning, which is the
     * only report there is — the handler below is what turns that report into a refusal, since
     * a payload quietly missing two thirds of a delivery is worse than one reported unread: a
     * handler acts on it. Past its nesting ceiling it drops the field silently, and there the
     * package can only see the case where nothing at all survived; a body that loses one
     * over-nested field among ordinary ones is read as those ordinary fields.
     *
     * @return array{0: PayloadFormat, 1: array<array-key, mixed>}
     */
    private static function form(string $rawBody): array
    {
        $reported = false;

        set_error_handler(static function () use (&$reported): bool {
            $reported = true;

            return true;
        });

        try {
            parse_str($rawBody, $fields);
        } finally {
            restore_error_handler();
        }

        return $reported || $fields === []
            ? [PayloadFormat::Unreadable, []]
            : [PayloadFormat::Form, self::validUtf8($fields)];
    }

    /**
     * The bare media type: parameters (`; charset=utf-8`), surrounding space and casing all
     * removed, since a producer may send any of them and none of them change the format.
     */
    private static function mediaType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    /**
     * The fields with any invalid UTF-8 substituted.
     *
     * Percent-escapes decode to arbitrary bytes, so `name=Andr%E9` yields a Latin-1 e-acute. A
     * JSON payload can never contain one — json_decode rejects the body before this point — so
     * everything downstream is built on the assumption that it cannot be there. Storing the
     * payload and serializing the handler job onto the queue both encode as JSON and THROW on
     * such a byte, after the signature already verified: a 500 on every retry and the delivery
     * lost, the same way round as the defect this decoder was written for. The search excerpt
     * does not throw; it yields an empty excerpt instead, which is quieter and no better.
     *
     * Substituting where the bytes enter is what keeps that assumption true for every one of
     * them, and it is the same lossy-but-valid trade the stored payload already makes for NUL
     * bytes. Nothing is destroyed: the exact bytes are kept beside the payload, and
     * `WebhookCall::body()` returns them.
     *
     * @param  array<array-key, mixed>  $fields
     * @return array<array-key, mixed>
     */
    private static function validUtf8(array $fields): array
    {
        $encoded = json_encode($fields, JSON_INVALID_UTF8_SUBSTITUTE);
        $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }
}
