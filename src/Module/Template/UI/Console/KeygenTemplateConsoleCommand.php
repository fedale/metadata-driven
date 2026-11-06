<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use Fedale\TemplateFormat\Signature;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:keygen', description: 'Make a key pair to sign the templates of an index with')]
final class KeygenTemplateConsoleCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The file to write the secret key to; keep it out of the repository')] string $file,
    ): int {
        if (file_exists($file)) {
            $io->error(\sprintf('%s exists: a key is never overwritten.', $file));

            return Command::FAILURE;
        }
        $keys = Signature::keyPair();
        // Readable by its owner only, from the start.
        $previous = umask(0o077);
        $written = file_put_contents($file, $keys['secret']."\n");
        umask($previous);
        if (false === $written) {
            $io->error(\sprintf('%s cannot be written.', $file));

            return Command::FAILURE;
        }

        $io->definitionList(['Secret key' => $file], ['Public key' => $keys['public']]);
        $io->success('Sign with metadata:template:pack --sign; give the public key to whoever adds your index (metadata:template:repo add --key).');

        return Command::SUCCESS;
    }
}
