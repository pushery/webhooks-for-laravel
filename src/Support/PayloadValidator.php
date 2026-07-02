<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use Webhooks\Exceptions\InvalidPayloadException;

/**
 * Validates an event payload against the JSON Schema declared for its type in the
 * event catalog. Validation only runs when 'webhooks.validate_payloads' is enabled
 * and the event type declares a 'schema'; otherwise the payload passes untouched,
 * so the catalog stays a pure documentation aid unless a schema is opted in.
 */
final readonly class PayloadValidator
{
    public function __construct(private WebhookConfig $config) {}

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

        $error = (new Validator)
            ->validate(Helper::toJSON($payload), $document)
            ->error();

        if ($error instanceof ValidationError) {
            throw InvalidPayloadException::forEvent($eventType, (new ErrorFormatter)->format($error));
        }
    }
}
