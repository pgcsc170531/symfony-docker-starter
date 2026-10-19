<?php

namespace App\Command;

use App\Entity\Tenant\Term;
use App\Service\Fees\FeeReportNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Weekly outstanding-fees email to the proprietor.
 *
 * It never messages a parent: the debtors screen produces the guardian
 * reminder drafts, and a human sends them.
 *
 *   docker compose exec -T php php bin/console app:fees:send-aging-reminders --dry-run
 */
#[AsCommand(
    name: 'app:fees:send-aging-reminders',
    description: 'Email the weekly outstanding-fees position to each school\'s admin users',
)]
final class SendAgingRemindersCommand extends TenantAwareCommand
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        Connection $tenantConnection,
        EntityManagerInterface $tenantEm,
        private readonly FeeReportNotifier $notifier,
    ) {
        parent::__construct($landlordEm, $tenantConnection, $tenantEm);
    }

    protected function configure(): void
    {
        $this
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this subdomain')
            ->addOption('term', null, InputOption::VALUE_REQUIRED, 'Term id (default: the active term)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Override recipient email (testing)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $termId = $input->getOption('term') !== null ? (int) $input->getOption('term') : null;
        $recipient = $input->getOption('to') !== null ? (string) $input->getOption('to') : null;

        $schools = $this->schools($input->getOption('school') !== null ? (string) $input->getOption('school') : null);
        if ($schools === []) {
            $io->warning('No active schools matched.');

            return Command::SUCCESS;
        }

        $io->title('Weekly outstanding-fees email' . ($dryRun ? ' (dry run)' : ''));
        $sentTotal = 0;

        foreach ($schools as $school) {
            $io->section($school->getName() . ' (' . $school->getSubdomain() . ')');

            try {
                $this->switchTo($school);
            } catch (\Throwable $e) {
                $io->error('Could not connect: ' . $e->getMessage());
                continue;
            }

            $term = $termId !== null
                ? $this->tenantEm->getRepository(Term::class)->find($termId)
                : $this->tenantEm->getRepository(Term::class)->findOneBy(['isActive' => true]);

            if (!$term instanceof Term) {
                $io->text('No term to report on.');
                continue;
            }

            $label = ($term->getSession()?->getName() ?? '—') . ' · ' . $term->getName();

            if ($dryRun) {
                $io->text('Would send the weekly position for ' . $label);
                continue;
            }

            $result = $this->notifier->sendAgingReminder($term, $recipient);

            if ($result['sent']) {
                $sentTotal++;
                $io->success(sprintf('%s → %s', $label, implode(', ', $result['recipients'])));
            } else {
                $io->warning(sprintf('%s: %s', $label, $result['reason'] ?? 'not sent'));
            }
        }

        $io->writeln(sprintf('<info>Done. %d aging email(s) sent.</info>', $sentTotal));

        return Command::SUCCESS;
    }
}
