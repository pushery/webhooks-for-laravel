<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing\Console;

use Illuminate\Console\Command;
use Pushery\Webhooks\Core\Signing\Ed25519Keys;
use Pushery\Webhooks\Core\Signing\Ed25519Scheme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a fresh Ed25519 keypair for the asymmetric {@see Ed25519Scheme}.
 * Keep the secret key on the sending side (its config secret / WEBHOOKS_ED25519_SECRET_KEY)
 * and hand the public key to receivers — statically, or served from a JWKS endpoint.
 * Console-only; it emits key material and never runs as part of a request.
 *
 * @internal
 */
#[AsCommand(name: 'webhooks:ed25519-keygen')]
final class Ed25519KeygenCommand extends Command
{
    protected $signature = 'webhooks:ed25519-keygen';

    protected $description = 'Generate an Ed25519 keypair for asymmetric webhook signing (v1a).';

    public function handle(): int
    {
        $keys = Ed25519Keys::generate();

        $this->line('Public key (share with receivers):');
        $this->output->writeln($keys['public'], OutputInterface::OUTPUT_RAW);
        $this->newLine();
        $this->line('Secret key (keep private on the sender):');
        $this->output->writeln($keys['secret'], OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
