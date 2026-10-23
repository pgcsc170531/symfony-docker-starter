<?php

namespace App\Command;

use App\Service\Settings\SecretCipher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a fresh `APP_ENCRYPTION_KEY`.
 *
 * Deliberately does NOT write it anywhere: the key belongs in the server's
 * environment (or `.env.local` on a laptop), and a command that silently edits a
 * file is how a key ends up committed. Copy it where it belongs.
 */
#[AsCommand(
    name: 'app:platform:make-key',
    description: 'Print a new APP_ENCRYPTION_KEY for encrypting stored secrets',
)]
class MakeEncryptionKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = SecretCipher::generateKey();

        $io->section('New APP_ENCRYPTION_KEY');
        $io->writeln($key);
        $io->newLine();
        $io->writeln('Add it to the server environment (or .env.local on a laptop):');
        $io->writeln('  APP_ENCRYPTION_KEY=' . $key);
        $io->newLine();
        $io->warning([
            'Store it somewhere safe and keep it out of git.',
            'Losing this key means every secret saved through the admin screens must be re-entered.',
        ]);

        return Command::SUCCESS;
    }
}
