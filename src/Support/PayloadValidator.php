<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use stdClass;
use Webhooks\Exceptions\InvalidPayloadException;

/**
 * Validates an event payload against the JSON Schema declared for its type in the
 * event catalog. Validation only runs when 'webhooks.platform.validate_payloads' is enabled
 * and the event type declares a 'schema'; otherwise the payload passes untouched,
 * so the catalog stays a pure documentation aid unless a schema is opted in.
 *
 * @internal
 */
final readonly class PayloadValidator
{
    public function __construct(private Settings $config) {}

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidPayloadException when validation is enabled and the payload
     *                                 does not satisfy the event type's schema.
     */
    public function validate(string $eventType, array $payload): void
    {
        if (! $this->config->validatePayloads()) {
            return;
        }

        // opis needs the schema as decoded JSON. A missing schema (null) or an empty
        // one ([]) decodes to a non-object and constrains nothing — nothing to check.
        $document = Helper::toJSON($this->config->schemaFor($eventType) ?? []);

        if (! is_object($document)) {
            return;
        }

        // A webhook payload is a JSON object, but PHP cannot tell an empty list from an
        // empty map, so opis's Helper::toJSON renders [] as a JSON ARRAY — which then fails
        // an object schema even though {} would satisfy it. Validate an empty payload as the
        // empty object it represents; a non-empty payload keeps opis's list/map detection.
        $instance = $payload === [] ? new stdClass : Helper::toJSON($payload);

        $error = (new Validator)
            ->validate($instance, $document)
            ->error();

        if ($error instanceof ValidationError) {
            throw InvalidPayloadException::forEvent($eventType, (new ErrorFormatter)->format($error));
        }
    }
}
