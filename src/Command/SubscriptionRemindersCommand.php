<?php

namespace App\Command;

use App\Entity\Landlord\Subscription;
use App\Entity\Landlord\SubscriptionPayment;
use App\Service\Subscription\SubscriptionMailer;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily billing reminders — the schedule the design promised (T-14, T-7, T-1 for
 * renewals; a nudge for invoices left unpaid for three days).
 *
 * Idempotent per day: `lastReminderSentAt` is stamped on the row, so running the
 * command twice (or having two cron entries) cannot email a school twice.
 *
 *     docker compose exec -T php php bin/console app:subscription:reminders --dry-run
 *     docker compose exec -T php php bin/console app:subscription:reminders
 *
 * Suggested cron: once a day, e.g. 08:00 server time.
 */
#[AsCommand(
    name: 'app:subscription:reminders',
    description: 'Send renewal and unpaid-invoice reminders to schools.'
)]
class SubscriptionRemindersCommand extends Command
{
    /** Days before expiry that trigger a renewal email. */
    private const RENEWAL_DAYS = [14, 7, 1];

    /** An invoice must be this many days old before it is chased. */
    private const INVOICE_GRACE_DAYS = 3;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be sent, without sending anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $em = $this->doctrine->getManager('landlord');
        $today = new \DateTimeImmutable('today');

        $renewals = 0;
        $invoices = 0;
        $skipped = 0;

        // ── Renewals: T-14, T-7, T-1 ─────────────────────────────────────────
        foreach (self::RENEWAL_DAYS as $days) {
            $target = $today->modify("+{$days} days");

            $subscriptions = $em->getRepository(Subscription::class)
                ->createQueryBuilder('s')
                ->where('s.endDate >= :start')
                ->andWhere('s.endDate < :end')
                ->setParameter('start', $target)
                ->setParameter('end', $target->modify('+1 day'))
                ->getQuery()
                ->getResult();

            foreach ($subscriptions as $subscription) {
                if ($subscription->getLastReminderSentAt()?->format('Y-m-d') === $today->format('Y-m-d')) {
                    ++$skipped;
                    continue;
                }

                $school = $subscription->getSchool()?->getName() ?? 'school';
                if ($dryRun) {
                    $io->writeln(sprintf('[dry-run] renewal T-%d → %s', $days, $school));
                } elseif ($this->mailer->sendExpiryReminder($subscription)) {
                    $subscription->setLastReminderSentAt($today);
                    ++$renewals;
                } else {
                    $io->warning(sprintf('%s has no valid billing email — renewal T-%d not sent.', $school, $days));
                }
            }
        }

        // ── Unpaid invoices ─────────────────────────────────────────────────
        $cutoff = $today->modify(sprintf('-%d days', self::INVOICE_GRACE_DAYS));

        $payments = $em->getRepository(SubscriptionPayment::class)
            ->createQueryBuilder('p')
            ->where('p.status IN (:statuses)')
            ->andWhere('p.createdAt <= :cutoff')
            ->setParameter('statuses', ['PENDING', 'VERIFYING'])
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        foreach ($payments as $payment) {
            if ($payment->getLastReminderSentAt()?->format('Y-m-d') === $today->format('Y-m-d')) {
                ++$skipped;
                continue;
            }

            $label = sprintf('%s / %s', $payment->getSchool()?->getName() ?? 'school', $payment->getReference());
            if ($dryRun) {
                $io->writeln(sprintf('[dry-run] unpaid invoice → %s', $label));
            } elseif ($this->mailer->sendReminder($payment)) {
                $payment->setLastReminderSentAt($today);
                ++$invoices;
            } else {
                $io->warning(sprintf('%s has no valid billing email — invoice reminder not sent.', $label));
            }
        }

        if (!$dryRun) {
            $em->flush();
        }

        $io->success(sprintf(
            '%sRenewal reminders: %d · invoice reminders: %d · skipped (already sent today): %d',
            $dryRun ? '[dry-run] ' : '',
            $renewals,
            $invoices,
            $skipped
        ));

        return Command::SUCCESS;
    }
}
