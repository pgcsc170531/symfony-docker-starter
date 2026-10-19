<?php

namespace App\Command;

use App\Entity\Tenant\Term;
use App\Service\Fees\FeeReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only snapshot of the active term's money.
 *
 * This is the command the end-of-term email will eventually call. It is also
 * the quickest way to prove that FeeReportService's queries work against a
 * real tenant database:
 *
 *   docker compose exec -T -e DATABASE_URL="mysql://root:root@database:3306/school_demo?serverVersion=8.0" \
 *       php php bin/console app:fees:summary
 */
#[AsCommand(
    name: 'app:fees:summary',
    description: 'Print billed, collected, outstanding and income-statement totals for the active term',
)]
class FeesSummaryCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeeReportService $reports,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $term = $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if (!$term) {
            $io->error('No active term in this database.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Fee summary — %s / %s', $term->getSession()?->getName() ?? '—', $term->getName()));

        $totals = $this->reports->termTotals($term);
        $io->table(['Figure', 'Amount'], [
            ['Net billable', '₦' . number_format($totals['netBillable'], 2)],
            ['Discounts', '₦' . number_format($totals['discount'], 2)],
            ['Collected', '₦' . number_format($totals['collected'], 2)],
            ['Pending (unverified)', '₦' . number_format($totals['pending'], 2)],
            ['Outstanding', '₦' . number_format($totals['outstanding'], 2)],
            ['Credit', '₦' . number_format($totals['credit'], 2)],
            ['Collection rate', $totals['collectionRate'] === null ? '—' : $totals['collectionRate'] . '%'],
        ]);

        $classes = $this->reports->classCollection($term);
        if ($classes !== []) {
            $io->section('By class');
            $io->table(
                ['Class', 'Pupils', 'Billed', 'Collected', 'Outstanding'],
                array_map(static fn (array $row): array => [
                    $row['class'] ?? 'Unassigned',
                    $row['pupils'],
                    '₦' . number_format($row['billed'], 2),
                    '₦' . number_format($row['collected'], 2),
                    '₦' . number_format($row['outstanding'], 2),
                ], $classes)
            );
        }

        $debtors = $this->reports->guardianDebtors($term);
        $io->section(sprintf('Families owing (%d)', count($debtors)));
        if ($debtors === []) {
            $io->text('Nobody owes anything for this term.');
        } else {
            $io->table(
                ['Guardian', 'Phone', 'Pupils', 'Invoiced', 'Paid', 'Outstanding'],
                array_map(static fn (array $row): array => [
                    $row['guardian'] ?? 'No guardian assigned',
                    $row['phone'] ?? '—',
                    $row['pupils'],
                    '₦' . number_format($row['invoiced'], 2),
                    '₦' . number_format($row['paid'], 2),
                    '₦' . number_format($row['outstanding'], 2),
                ], $debtors)
            );
        }

        $statement = $this->reports->incomeStatement($term);
        $io->section('Income statement');
        if ($statement['periodMissing']) {
            $io->warning('The term has no start/end date, so expenses and application fees were not included.');
        }
        $io->table(['Line', 'Accrual', 'Cash'], [
            ['Academic fees', '₦' . number_format($statement['academic']['accrued'], 2), '₦' . number_format($statement['academic']['collected'], 2)],
            ['Store', '₦' . number_format($statement['store']['accrued'], 2), '₦' . number_format($statement['store']['collected'], 2)],
            ['Application fees', '₦' . number_format($statement['applicationFees']['paid'] + $statement['applicationFees']['waived'], 2), '₦' . number_format($statement['applicationFees']['paid'], 2)],
            ['Total income', '₦' . number_format($statement['income']['accrual'], 2), '₦' . number_format($statement['income']['cash'], 2)],
            ['Expenses', '₦' . number_format($statement['expenses']['total'], 2), '₦' . number_format($statement['expenses']['total'], 2)],
            ['Net surplus / (deficit)', '₦' . number_format($statement['net']['accrual'], 2), '₦' . number_format($statement['net']['cash'], 2)],
        ]);

        return Command::SUCCESS;
    }
}
