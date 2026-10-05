<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\AsyncApi;

use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Support\Settings;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds an AsyncAPI 3.0 document from the event catalog, so the events your
 * application emits can be published as a machine-readable contract. Each catalog
 * entry becomes one channel, one send operation and one message; the message
 * carries the entry's JSON Schema as its payload plus its example and description,
 * so the same catalog that validates and documents an event also describes it here.
 *
 * @internal
 */
final readonly class AsyncApiGenerator
{
    private const string ASYNCAPI_VERSION = '3.0.0';

    public function __construct(private Settings $config) {}

    /**
     * The AsyncAPI document as a nested array. Empty maps (an empty catalog) are
     * rendered as JSON objects rather than arrays, so the output stays a valid
     * AsyncAPI document even with no event types declared.
     *
     * A null title stands for the application's name, which is what the command has always
     * used, so the console and the public method describe one catalog under one name.
     *
     * @return array<string, mixed>
     */
    public function generate(?string $title, string $version): array
    {
        $title ??= Config::string('app.name', 'Webhooks').' Webhooks';

        $channels = [];
        $operations = [];
        $messages = [];

        foreach ($this->config->catalog() as $type => $meta) {
            $meta = is_array($meta) ? $meta : [];

            $description = isset($meta['description']) && is_string($meta['description']) ? $meta['description'] : null;
            $schema = isset($meta['schema']) && is_array($meta['schema']) ? $meta['schema'] : ['type' => 'object'];
            $example = $meta['example'] ?? null;

            $channels[$type] = array_filter([
                'address' => $type,
                'description' => $description,
                'messages' => [$type => ['$ref' => '#/components/messages/'.$type]],
            ], static fn (mixed $value): bool => $value !== null);

            $operations[$type] = [
                'action' => 'send',
                'channel' => ['$ref' => '#/channels/'.$type],
                'messages' => [['$ref' => '#/channels/'.$type.'/messages/'.$type]],
            ];

            $messages[$type] = array_filter([
                'name' => $type,
                'title' => $type,
                'summary' => $description,
                'contentType' => 'application/json',
                'payload' => $schema,
                'examples' => $example !== null ? [['name' => 'example', 'payload' => $example]] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'asyncapi' => self::ASYNCAPI_VERSION,
            'info' => ['title' => $title, 'version' => $version],
            'defaultContentType' => 'application/json',
            'channels' => $this->mapOrObject($channels),
            'operations' => $this->mapOrObject($operations),
            'components' => ['messages' => $this->mapOrObject($messages)],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function toJson(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function toYaml(array $document): string
    {
        // Round-trip through JSON to OBJECTS, not arrays, so an empty JSON object and an empty
        // JSON list stay apart: the dumper writes the first as a map and the second as a list.
        // Decoded to arrays, both arrive as [] and both leave as `{}`, and an empty `required` in
        // a catalog schema, or an empty list in an example, turns into a map in the YAML while
        // the JSON document keeps the list.
        $encoded = json_encode($document) ?: '{}';
        $normalized = json_decode($encoded, false);

        // No inline level is deep enough to be safe as a number: a catalog schema sits four levels
        // down and every nested object adds two, so a schema three objects deep already reached
        // the tenth level and switched to inline flow style there. The document is meant to be
        // read and diffed by a person, so it never switches, and its indent stays at two spaces
        // from one release to the next.
        return Yaml::dump(
            $normalized instanceof stdClass ? $normalized : new stdClass,
            PHP_INT_MAX,
            2,
            Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    /**
     * A JSON map that renders as an object even when empty: an empty PHP array would
     * otherwise encode to `[]`, but an AsyncAPI channels/operations/messages node
     * must be an object (`{}`).
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>|stdClass
     */
    private function mapOrObject(array $map): array|stdClass
    {
        return $map === [] ? new stdClass : $map;
    }
}
