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
 * End-of-term summary email, once per term.
 *
 * Run daily from cron; a term is due when its `end_date` has passed and
 * `summary_sent_at` is still null. `--force` re-sends (and refreshes the PDF
 * figures, since the summary is a snapshot at send time).
 *
 *   docker compose exec -T php php bin/console app:fees:send-term-summaries --dry-run
 */
#[AsCommand(
    name: 'app:fees:send-term-summaries',
    description: 'Email the Term Fee Summary PDF to each school\'s admin users after a term ends',
)]
final class SendTermSummariesCommand extends TenantAwareCommand
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
            ->addOption('term', null, InputOption::VALUE_REQUIRED, 'Only this term id')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'How many days back to look for ended terms', '60')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Override recipient email (testing)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-send even if already sent')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $termId = $input->getOption('term') !== null ? (int) $input->getOption('term') : null;
        $recipient = $input->getOption('to') !== null ? (string) $input->getOption('to') : null;

        $schools = $this->schools($input->getOption('school') !== null ? (string) $input->getOption('school') : null);
        if ($schools === []) {
            $io->warning('No active schools matched.');

            return Command::SUCCESS;
        }

        $io->title('Term fee summaries' . ($dryRun ? ' (dry run)' : ''));
        $sentTotal = 0;

        foreach ($schools as $school) {
            $io->section($school->getName() . ' (' . $school->getSubdomain() . ')');

            try {
                $this->switchTo($school);
            } catch (\Throwable $e) {
                $io->error('Could not connect: ' . $e->getMessage());
                continue;
            }

            $qb = $this->tenantEm->getRepository(Term::class)->createQueryBuilder('t');

            if ($termId !== null) {
                $qb->andWhere('t.id = :id')->setParameter('id', $termId);
            } else {
                $qb->andWhere('t.endDate IS NOT NULL')
                   ->andWhere('t.endDate <= :today')
                   ->setParameter('today', new \DateTimeImmutable('today'));

                $since = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', max(1, (int) $input->getOption('since'))));
                $qb->andWhere('t.endDate >= :since')->setParameter('since', $since);
            }

            if (!$force) {
                $qb->andWhere('t.summarySentAt IS NULL');
            }

            /** @var Term[] $terms */
            $terms = $qb->orderBy('t.endDate', 'ASC')->getQuery()->getResult();

            if ($terms === []) {
                $io->text('No ended term is due.');
                continue;
            }

            foreach ($terms as $term) {
                $label = ($term->getSession()?->getName() ?? '—') . ' · ' . $term->getName();

                if ($dryRun) {
                    $io->text(sprintf('Would send %s (ended %s)', $label, $term->getEndDate()?->format('j M Y') ?? '—'));
                    continue;
                }

                $result = $this->notifier->sendTermSummary($term, $recipient, $force);

                if ($result['sent']) {
                    $sentTotal++;
                    $io->success(sprintf('%s → %s', $label, implode(', ', $result['recipients'])));
                } else {
                    $io->warning(sprintf('%s: %s', $label, $result['reason'] ?? 'not sent'));
                }
            }
        }

        $io->writeln(sprintf('<info>Done. %d summary email(s) sent.</info>', $sentTotal));

        return Command::SUCCESS;
    }
}
