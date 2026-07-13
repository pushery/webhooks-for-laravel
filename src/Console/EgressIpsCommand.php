<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Prints the published egress-IP allowlist — the fixed set of source IPs your
 * deliveries leave from — so a consumer can copy it straight onto its firewall. The
 * list is read from webhooks.core.egress.published_ips in config order and rendered
 * in one of three formats: json (default, a JSON array), txt (one IP per line), or md
 * (a markdown bullet list). An empty list yields the empty representation of the
 * chosen format. Console-only, registered by the root service provider.
 *
 * @internal
 */
final class EgressIpsCommand extends Command
{
    protected $signature = 'webhooks:egress-ips
        {--format=json : Output format — json (default), txt or md}';

    protected $description = 'Print the published egress-IP allowlist consumers should whitelist.';

    public function handle(): int
    {
        $formatOption = $this->option('format');
        $format = strtolower(is_string($formatOption) ? $formatOption : 'json');

        if (! in_array($format, ['json', 'txt', 'md'], true)) {
            $this->components->error(sprintf('Unknown format "%s". Use json, txt or md.', $format));

            return self::FAILURE;
        }

        $this->line(self::render($this->publishedIps(), $format));

        return self::SUCCESS;
    }

    /**
     * Render the allowlist deterministically in the requested format. Pure and static
     * so every format branch is directly testable without a live command run.
     *
     * @param  list<string>  $ips
     */
    public static function render(array $ips, string $format): string
    {
        return match ($format) {
            'txt' => implode("\n", $ips),
            'md' => implode("\n", array_map(static fn (string $ip): string => "- {$ip}", $ips)),
            default => json_encode($ips, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * The configured egress IPs as a clean list of non-empty strings, in config order.
     *
     * @return list<string>
     */
    private function publishedIps(): array
    {
        $configured = Config::array('webhooks.core.egress.published_ips', []);

        $ips = array_map(
            static fn (mixed $ip): string => is_string($ip) ? $ip : '',
            $configured,
        );

        // Re-indexed once, at the end: a hand-keyed or gap-keyed config must still print
        // as a JSON array, not as an object a firewall cannot read.
        return array_values(array_filter($ips, static fn (string $ip): bool => $ip !== ''));
    }
}
