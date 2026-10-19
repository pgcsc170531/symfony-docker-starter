<?php

namespace App\Command;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Service\SchoolMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders and sends one of the parent notification emails, using real data from
 * the current tenant.
 *
 * This is how we prove the templates work (they land in Mailpit in dev) and how
 * a school can see exactly what a parent receives before switching a trigger on.
 *
 * The recipient's address is swapped **in memory only** — nothing is written to
 * the database, so no real parent is ever spammed by a preview.
 *
 *   docker compose exec php bin/console app:mail:preview application_submitted you@example.com
 */
#[AsCommand(
    name: 'app:mail:preview',
    description: 'Send a sample of one parent notification email, using this tenant\'s real data',
)]
class PreviewMailCommand extends Command
{
    private const EVENTS = [
        School::EMAIL_APPLICATION_SUBMITTED,
        School::EMAIL_ADMISSION_DECISION,
        School::EMAIL_INVOICE_ISSUED,
        School::EMAIL_PAYMENT_RECEIPT,
        School::EMAIL_RESULT_PUBLISHED,
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private SchoolMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('event', InputArgument::REQUIRED, 'One of: ' . implode(', ', self::EVENTS))
            ->addArgument('to', InputArgument::REQUIRED, 'Where to send the preview')
            ->setHelp(<<<'HELP'
Sends one parent notification email to the address you give, built from this
tenant's real records. Override DATABASE_URL to choose the school:

  <info>docker compose exec -T \
    -e DATABASE_URL="mysql://root:root@database:3306/school_gobarau?serverVersion=8.0" \
    php bin/console app:mail:preview application_submitted you@example.com</info>

In development everything lands in Mailpit at http://localhost:8025.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $event = (string) $input->getArgument('event');
        $to = (string) $input->getArgument('to');

        if (!in_array($event, self::EVENTS, true)) {
            $io->error(sprintf('Unknown event "%s".', $event));
            $io->listing(self::EVENTS);

            return Command::INVALID;
        }

        $school = $this->em->getRepository(School::class)->findOneBy([]);
        if (!$school) {
            $io->error('No school settings row found. Is DATABASE_URL pointing at a tenant database?');

            return Command::FAILURE;
        }

        $io->text(sprintf('School: <info>%s</info>', $school->getName()));

        if (!$school->isEmailEnabledFor($event)) {
            $io->warning(sprintf(
                'This school has the "%s" email switched off, so nothing was sent. '
                . 'Turn it on in Settings → Email Notifications.',
                $event
            ));

            return Command::SUCCESS;
        }

        $sent = match ($event) {
            School::EMAIL_APPLICATION_SUBMITTED => $this->previewApplication($io, $school, $to),
            School::EMAIL_ADMISSION_DECISION    => $this->previewAdmission($io, $school, $to),
            School::EMAIL_INVOICE_ISSUED        => $this->previewInvoice($io, $school, $to),
            School::EMAIL_PAYMENT_RECEIPT       => $this->previewPayment($io, $school, $to),
            School::EMAIL_RESULT_PUBLISHED      => $this->previewResult($io, $school, $to),
            default => false,
        };

        if (!$sent) {
            return Command::FAILURE;
        }

        $io->success(sprintf('Preview sent to %s. In dev, open http://localhost:8025', $to));

        return Command::SUCCESS;
    }

    private function previewApplication(SymfonyStyle $io, School $school, string $to): bool
    {
        $application = $this->em->getRepository(Application::class)
            ->findOneBy([], ['id' => 'DESC']);

        if (!$application) {
            $io->error('No application records exist on this tenant.');

            return false;
        }

        $this->pointGuardianAt($io, $application->getGuardian(), $application->getGuardianEmail(), $to);

        return $this->mailer->applicationSubmitted($school, $application, null, null);
    }

    private function previewAdmission(SymfonyStyle $io, School $school, string $to): bool
    {
        $student = $this->firstStudent($io);
        if (!$student) {
            return false;
        }

        $this->pointGuardianAt($io, $student->getGuardian(), null, $to);

        return $this->mailer->admissionDecision($school, $student, approved: true);
    }

    private function previewInvoice(SymfonyStyle $io, School $school, string $to): bool
    {
        // Walk-in store invoices have no student, so they can never be addressed
        // to a parent — pick the newest invoice that does have one.
        $invoice = $this->em->getRepository(Invoice::class)
            ->createQueryBuilder('i')
            ->innerJoin('i.student', 's')
            ->innerJoin('s.guardian', 'g')
            ->andWhere('g.email IS NOT NULL')
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$invoice) {
            $io->error('No invoice linked to a student with a guardian email exists on this tenant.');

            return false;
        }

        $this->pointGuardianAt($io, $invoice->getStudent()->getGuardian(), null, $to);

        return $this->mailer->invoiceIssued($school, $invoice);
    }

    private function previewPayment(SymfonyStyle $io, School $school, string $to): bool
    {
        $payment = $this->em->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->innerJoin('p.invoice', 'i')
            ->innerJoin('i.student', 's')
            ->innerJoin('s.guardian', 'g')
            ->andWhere('g.email IS NOT NULL')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$payment) {
            $io->error('No payment linked to a student with a guardian email exists on this tenant.');

            return false;
        }

        $this->pointGuardianAt($io, $payment->getInvoice()->getStudent()->getGuardian(), null, $to);

        return $this->mailer->paymentReceipt($school, $payment);
    }

    private function previewResult(SymfonyStyle $io, School $school, string $to): bool
    {
        $student = $this->firstStudent($io);
        if (!$student) {
            return false;
        }

        $this->pointGuardianAt($io, $student->getGuardian(), null, $to);
        $term = $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        return $this->mailer->resultPublished($school, $student, $term);
    }

    private function firstStudent(SymfonyStyle $io): ?Student
    {
        $student = $this->em->getRepository(Student::class)
            ->createQueryBuilder('s')
            ->innerJoin('s.guardian', 'g')
            ->andWhere('g.email IS NOT NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$student) {
            $io->error('No student with a guardian email exists on this tenant.');
        }

        return $student;
    }

    /**
     * Redirects the preview to the given address **in memory**. The change is
     * never flushed, so the real record keeps the parent's true email.
     */
    private function pointGuardianAt(SymfonyStyle $io, ?Guardian $guardian, ?string $fallbackEmail, string $to): void
    {
        if ($guardian === null) {
            if ($fallbackEmail === null) {
                $io->warning('This record has no guardian email of its own; the preview still uses the address you gave.');
            }

            return;
        }

        $guardian->setEmail($to);
        $io->text(sprintf('Recipient overridden to <info>%s</info> (in memory only).', $to));
    }
}
