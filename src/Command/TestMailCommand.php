<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * One-command email check for any environment:
 *
 *   docker compose exec -e APP_ENV=prod -T php bin/console app:mail:test you@example.com
 *
 * It prints the transport that is ACTUALLY configured (with the password masked)
 * and the real SMTP error when sending fails, so "the email didn't arrive" stops
 * being a guessing game on the server.
 */
#[AsCommand(
    name: 'app:mail:test',
    description: 'Send a test email to verify the MAILER_DSN (Brevo/SMTP) configuration',
)]
class TestMailCommand extends Command
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from = 'Edus <no-reply@edus.ng>',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Recipient email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = (string) $input->getArgument('to');

        $dsn = (string) ($_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: 'not set');
        // Never print the SMTP password.
        $masked = preg_replace('~://([^:@/]+):[^@]+@~', '://$1:***@', $dsn);

        $output->writeln('From      : ' . $this->from);
        $output->writeln('Transport : ' . $masked);

        if (str_starts_with((string) $dsn, 'null://')) {
            $output->writeln('<comment>⚠️  MAILER_DSN is null://null — messages are being thrown away.</comment>');
            $output->writeln('   Set a real DSN (Brevo) in the environment, then re-run this command.');
        }

        $host = gethostname() ?: 'server';

        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject('Edus mail test — ' . date('Y-m-d H:i'))
            ->text("If you are reading this, outbound email works.\nSent from {$host}.")
            ->html('<p><strong>Email is working.</strong></p><p>Sent from ' . htmlspecialchars($host) . ' at ' . date('Y-m-d H:i') . '.</p>');

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<error>❌ Sending failed</error>');
            // Mask anything that looks like credentials in the error text too.
            $message = preg_replace('~://([^:@/]+):[^@]+@~', '://$1:***@', $e->getMessage());
            $output->writeln('   ' . $message);
            $output->writeln('');
            $output->writeln('Common causes: wrong SMTP login/key, sender not verified in Brevo,');
            $output->writeln('this server\'s IP not authorised in Brevo, or the port blocked by the firewall.');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>✅ Sent to ' . $to . '</info>');

        return Command::SUCCESS;
    }
}
