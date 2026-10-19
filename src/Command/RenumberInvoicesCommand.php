<?php

namespace App\Command;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\Invoice;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rewrites legacy invoice numbers into the `INV-{year}-{0001}` series.
 *
 * Exists because five screens used to mint five formats (`INV-<hex>`,
 * `INV-2026-<hex>`, `INV-AUTO-<hex>`), and a number a parent cannot read back
 * over the phone is not a reference. New invoices use the series automatically
 * (App\Service\Finance\InvoiceNumberGenerator); this command is for rows that
 * already exist.
 *
 * ⚠️ Renumbering changes what an already-issued statement or receipt shows. In a
 * live school, reprint the affected documents or leave the history alone. Run
 * without `--apply` first — that is the default and writes nothing.
 *
 * Idempotent: rows already in the series are skipped, and the sequence continues
 * from the highest number in the year rather than restarting.
 */
#[AsCommand(
    name: 'app:invoices:renumber',
    description: 'Rewrite legacy invoice numbers into the INV-YYYY-NNNN series (dry-run unless --apply).'
)]
final class RenumberInvoicesCommand extends TenantAwareCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Subdomain to renumber (default: every active school).')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the new numbers. Without this flag the command only reports.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many changes per school (0 = no limit).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $schools = $this->schools((string) $input->getOption('school'));

        if ($schools === []) {
            $io->error('No active school matched that subdomain.');

            return Command::FAILURE;
        }

        $apply = (bool) $input->getOption('apply');
        $limit = max(0, (int) $input->getOption('limit'));
        $totalChanged = 0;

        foreach ($schools as $school) {
            $this->switchTo($school);
            $totalChanged += $this->renumberSchool($school, $limit, $apply, $io);
        }

        if (!$apply && $totalChanged > 0) {
            $io->warning(sprintf(
                '%d invoice(s) across %d school(s) would be renumbered. Nothing was written — re-run with --apply.',
                $totalChanged,
                count($schools)
            ));
        }

        return Command::SUCCESS;
    }

    private function renumberSchool(LandlordSchool $school, int $limit, bool $apply, SymfonyStyle $io): int
    {
        /** @var Invoice[] $invoices */
        $invoices = $this->tenantEm->getRepository(Invoice::class)
            ->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var array<int, int> $next */
        $next = [];
        $legacy = [];

        foreach ($invoices as $invoice) {
            if (preg_match('/^INV-(\d{4})-(\d{4,})$/', (string) $invoice->getInvoiceNumber(), $m) === 1) {
                $next[(int) $m[1]] = max($next[(int) $m[1]] ?? 0, (int) $m[2]);
            } else {
                $legacy[] = $invoice;
            }
        }

        $io->section(sprintf('%s — %d invoice(s), %d legacy number(s)', $school->getName(), count($invoices), count($legacy)));

        if ($legacy === []) {
            return 0;
        }

        $rows = [];
        $changed = 0;

        foreach ($legacy as $invoice) {
            if ($limit > 0 && $changed >= $limit) {
                break;
            }

            $year = $this->yearOf($invoice);
            $next[$year] = ($next[$year] ?? 0) + 1;
            $newNumber = sprintf('INV-%d-%04d', $year, $next[$year]);

            $rows[] = [
                (string) $invoice->getId(),
                (string) $invoice->getInvoiceNumber(),
                $newNumber,
                (string) ($invoice->getStudent()?->getFullName() ?? $invoice->getBuyerName() ?? '—'),
            ];

            if ($apply) {
                $invoice->setInvoiceNumber($newNumber);
            }

            $changed++;
        }

        $io->table(['id', 'old number', 'new number', 'pupil / buyer'], $rows);

        if ($apply) {
            $this->tenantEm->flush();
            $io->success(sprintf('%d invoice(s) renumbered.', $changed));
        }

        return $changed;
    }

    /** The session's start year, falling back to the year the invoice was issued. */
    private function yearOf(Invoice $invoice): int
    {
        $sessionName = $invoice->getSession()?->getName();

        if ($sessionName !== null && preg_match('/^\s*(\d{4})/', $sessionName, $m) === 1) {
            return (int) $m[1];
        }

        return (int) ($invoice->getCreatedAt()?->format('Y') ?? date('Y'));
    }
}
