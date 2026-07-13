<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Yaml\Yaml;
use Webhooks\Platform\AsyncApi\AsyncApiGenerator;

/**
 * Writes an AsyncAPI 3.0 document built from the webhook event catalog, either to a
 * file (the optional path argument) or to stdout. JSON by default; YAML with
 * --format=yaml when symfony/yaml is installed. Console-only, registered by the root
 * service provider.
 *
 * @internal
 */
final class AsyncApiCommand extends Command
{
    protected $signature = 'webhooks:asyncapi
        {path? : File to write the document to; omit to print to stdout}
        {--format=json : Output format — json (default) or yaml}
        {--title= : Document title (defaults to the app name)}
        {--doc-version=1.0.0 : Document version}';

    protected $description = 'Generate an AsyncAPI 3.0 document from the webhook event catalog.';

    public function handle(AsyncApiGenerator $generator): int
    {
        $formatOption = $this->option('format');
        $format = strtolower(is_string($formatOption) ? $formatOption : 'json');

        $error = self::formatError($format, class_exists(Yaml::class));

        if ($error !== null) {
            $this->components->error($error);

            return self::FAILURE;
        }

        $versionOption = $this->option('doc-version');
        $version = is_string($versionOption) && $versionOption !== '' ? $versionOption : '1.0.0';

        $document = $generator->generate($this->resolveTitle(), $version);
        $rendered = $format === 'yaml' ? $generator->toYaml($document) : $generator->toJson($document);

        $path = $this->argument('path');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $rendered.PHP_EOL);
            $this->components->info(sprintf('AsyncAPI document written to %s.', $path));

            return self::SUCCESS;
        }

        $this->line($rendered);

        return self::SUCCESS;
    }

    /**
     * The reason the requested format cannot be produced, or null when it can. Pure
     * and static so both the unknown-format and the yaml-unavailable branches are
     * directly testable without a live symfony/yaml toggle.
     */
    public static function formatError(string $format, bool $yamlAvailable): ?string
    {
        if (! in_array($format, ['json', 'yaml'], true)) {
            return sprintf('Unknown format "%s". Use json or yaml.', $format);
        }

        if ($format === 'yaml' && ! $yamlAvailable) {
            return 'YAML output requires symfony/yaml. Install it or use --format=json.';
        }

        return null;
    }

    private function resolveTitle(): string
    {
        $title = $this->option('title');

        return is_string($title) && $title !== ''
            ? $title
            : Config::string('app.name', 'Webhooks').' Webhooks';
    }
}
