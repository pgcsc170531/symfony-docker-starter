<?php

namespace App\Command;

use App\Entity\Landlord\Subscription;
use App\Entity\Landlord\SubscriptionPayment;
use App\Service\Subscription\BillingBankAccount;
use App\Service\Payment\PaystackClient;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Can we take money from a school today?" — one command that answers it.
 *
 * Everything it checks has already gone wrong once in this project: a payment
 * page advertising a placeholder account, a reminder command nothing called, a
 * test gateway key in an environment that looked production. A checklist that
 * runs is worth more than a paragraph in a runbook.
 *
 *     docker compose exec -T php php bin/console app:billing:readiness
 *
 * Run it before launch and after any deploy that touches env. Exit code 1 means
 * at least one check FAILED; warnings do not fail the command.
 */
#[AsCommand(
    name: 'app:billing:readiness',
    description: 'Check that live billing can actually collect money (keys, bank, mail, cron).'
)]
class BillingReadinessCommand extends Command
{
    /** A reminder older than this suggests cron is not running. */
    private const REMINDER_STALENESS_DAYS = 3;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly BillingBankAccount $bank,
        private readonly PaystackClient $paystack,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        #[Autowire('%app.base_domain%')]
        private readonly string $baseDomain,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_REPLY_TO)%')]
        private readonly string $mailerReplyTo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Edus — live billing readiness');

        $rows = [];
        $failures = 0;
        $warnings = 0;

        $check = function (string $name, bool $ok, string $detail, bool $fatal = true) use (&$rows, &$failures, &$warnings): void {
            if ($ok) {
                $rows[] = ['<fg=green>PASS</>', $name, $detail];
                return;
            }

            $rows[] = [$fatal ? '<fg=red>FAIL</>' : '<fg=yellow>WARN</>', $name, $detail];
            $fatal ? $failures++ : $warnings++;
        };

        // ── Environment ──────────────────────────────────────────────────
        $check(
            'Environment',
            $this->environment === 'prod',
            $this->environment === 'prod' ? 'APP_ENV=prod' : sprintf('APP_ENV=%s — set APP_ENV=prod on the server', $this->environment),
            false
        );

        $check(
            'Debug mode',
            !$this->debug,
            $this->debug ? 'APP_DEBUG is on — exception pages leak internals to schools' : 'APP_DEBUG=0',
            false
        );

        $check(
            'Base domain',
            !str_contains($this->baseDomain, 'localhost'),
            str_contains($this->baseDomain, 'localhost')
                ? sprintf('BASE_DOMAIN=%s — links in emails will point at localhost', $this->baseDomain)
                : sprintf('BASE_DOMAIN=%s', $this->baseDomain)
        );

        // ── Money in ─────────────────────────────────────────────────────
        $bank = $this->bank->toArray();
        $check(
            'Collection account',
            $this->bank->isConfigured(),
            $this->bank->isConfigured()
                ? sprintf('%s · %s', $bank['name'] ?: 'bank', $this->mask($bank['account']))
                : 'EDUS_BANK_ACCOUNT is empty — the pay page and every invoice will say "contact us"'
        );

        $gatewayReady = $this->paystack->isConfigured();
        $testKey = str_starts_with((string) $this->env('PAYSTACK_SECRET_KEY'), 'sk_test_');
        $check(
            'Payment gateway',
            $gatewayReady && !$testKey,
            match (true) {
                !$gatewayReady => 'No PAYSTACK_SECRET_KEY — online payment is hidden, transfers only',
                $testKey => 'PAYSTACK_SECRET_KEY is a TEST key — no real money will ever move',
                default => 'Paystack live key configured',
            },
            $gatewayReady
        );

        // ── Money out of the app: mail ───────────────────────────────────
        $check(
            'Sender address',
            filter_var($this->stripName($this->mailerFrom), FILTER_VALIDATE_EMAIL) !== false,
            sprintf('MAILER_FROM=%s', $this->mailerFrom),
            false
        );

        $check(
            'Reply-to address',
            filter_var($this->mailerReplyTo, FILTER_VALIDATE_EMAIL) !== false,
            sprintf('MAILER_REPLY_TO=%s', $this->mailerReplyTo ?: '(empty)'),
            false
        );

        // ── Reminders: is anything calling the command? ───────────────────
        $em = $this->doctrine->getManager('landlord');
        $today = new \DateTimeImmutable('today');
        $cutoff = $today->modify(sprintf('-%d days', self::REMINDER_STALENESS_DAYS));

        $dueSoon = $em->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.endDate BETWEEN :today AND :soon')
            ->andWhere('s.plan IS NOT NULL')
            ->setParameter('today', $today)
            ->setParameter('soon', $today->modify('+14 days'))
            ->getQuery()
            ->getSingleScalarResult();

        $lastReminder = $em->createQueryBuilder()
            ->select('MAX(s.lastReminderSentAt)')
            ->from(Subscription::class, 's')
            ->getQuery()
            ->getSingleScalarResult();

        $overdue = $em->getRepository(SubscriptionPayment::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status IN (:open)')
            ->andWhere('p.createdAt < :cutoff')
            ->setParameter('open', ['PENDING', 'VERIFYING'])
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();

        $check(
            'Reminder scheduler',
            (int) $dueSoon === 0 || $lastReminder !== null,
            match (true) {
                (int) $dueSoon === 0 => 'No subscription renews in the next 14 days — nothing to send',
                $lastReminder === null => sprintf('%d subscription(s) renew within 14 days and no reminder has ever been sent — is cron installed?', $dueSoon),
                default => sprintf('%d renewing within 14 days; last reminder %s', $dueSoon, $lastReminder),
            },
            (int) $dueSoon === 0
        );

        // ── Operational picture (informational) ──────────────────────────
        $expired = $em->getRepository(Subscription::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.endDate < :today')
            ->andWhere('s.plan IS NOT NULL')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        $io->table(['', 'Check', 'Detail'], array_map(
            static fn (array $r): array => [$r[0], $r[1], $r[2]],
            $rows
        ));

        $io->definitionList(
            ['Open invoices older than 3 days' => (string) $overdue],
            ['Subscriptions past their end date' => (string) $expired],
            ['Tenants on the landlord database' => (string) $em->getRepository(\App\Entity\Landlord\School::class)->count([])],
        );

        if ($failures > 0) {
            $io->error(sprintf('%d check(s) failed. Billing is NOT ready to collect money.', $failures));

            return Command::FAILURE;
        }

        if ($warnings > 0) {
            $io->warning(sprintf('Ready, with %d warning(s) — see above.', $warnings));

            return Command::SUCCESS;
        }

        $io->success('Billing is ready.');

        return Command::SUCCESS;
    }

    /** `sk_live_…` must never be printed in full, even in a terminal. */
    private function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '(empty)';
        }

        return strlen($value) <= 4
            ? str_repeat('•', strlen($value))
            : str_repeat('•', max(0, strlen($value) - 4)) . substr($value, -4);
    }

    /** "Edus <no-reply@edus.ng>" → "no-reply@edus.ng". */
    private function stripName(string $address): string
    {
        if (preg_match('/<([^>]+)>/', $address, $m) === 1) {
            return trim($m[1]);
        }

        return trim($address);
    }

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return $value === false ? null : (string) $value;
    }
}
