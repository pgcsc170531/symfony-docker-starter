<?php

namespace App\Command;

use App\Entity\Landlord\School as LandlordSchool;
use App\Service\Payment\LedgerPoster;
use App\Service\Payment\PayoutService;
use App\Service\Tenant\TenantConnectionSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pays each school the balance the ledger says we hold for it.
 *
 * Intended for cron, once a day: a parent's transfer arrives instantly and the
 * school's cash follows on the next run — "alert now, cash tomorrow" without any
 * human moving money.
 *
 * `--simulate` posts the real ledger entries while touching no bank, so the whole
 * cycle can be proven before a live transfer is risked.
 */
#[AsCommand(
    name: 'app:payments:payout',
    description: 'Pay schools what is held for them, from the ledger balance.'
)]
final class PayoutCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
        private readonly TenantConnectionSwitcher $switcher,
        private readonly PayoutService $payouts,
        private readonly LedgerPoster $ledger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this school subdomain')
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Skip schools holding less than this', '0')
            ->addOption('simulate', null, InputOption::VALUE_NONE, 'Post the ledger entries without moving money')
            ->addOption('live', null, InputOption::VALUE_NONE, 'Actually transfer money to the school (requires --simulate to be absent)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $subdomain = (string) $input->getOption('school');
        $minimum = (float) $input->getOption('min');
        $simulate = (bool) $input->getOption('simulate');
        $live = (bool) $input->getOption('live') && !$simulate;

        $criteria = ['isActive' => true];
        if ($subdomain !== '') {
            $criteria['subdomain'] = $subdomain;
        }

        /** @var LandlordSchool[] $schools */
        $schools = $this->landlordEm->getRepository(LandlordSchool::class)->findBy($criteria);

        if ($schools === []) {
            $io->error('No matching schools.');

            return Command::FAILURE;
        }

        if (!$simulate && !$live) {
            $io->warning('Nothing will move: pass --live to transfer money, or --simulate to post the books only.');
        }

        if ($live) {
            $io->caution('LIVE: money will leave the Paystack balance for each school below.');
        }

        $paid = 0;
        $skipped = 0;

        foreach ($schools as $school) {
            $this->switcher->switchTo($school);

            try {
                $result = $this->payouts->run($minimum, $simulate, $live);

                if ($result['ok'] && isset($result['payout'])) {
                    ++$paid;
                    $io->writeln(sprintf(
                        '<info>paid</info>   %s  %s  ₦%s',
                        $school->getSubdomain(),
                        $result['payout']->getReference(),
                        number_format($result['payout']->getAmountMinor() / 100, 2)
                    ));
                } else {
                    ++$skipped;
                    $io->writeln(sprintf('<comment>skip</comment>   %s — %s', $school->getSubdomain(), $result['message'] ?? ''));
                }

                $io->table(
                    ['Account', 'Balance after'],
                    [
                        ['1000 Cash at Paystack', number_format($this->ledger->cashAtPaystack(), 2)],
                        ['2000 School payable', number_format($this->ledger->schoolPayable(), 2)],
                        ['2100 Unallocated', number_format($this->ledger->unallocated(), 2)],
                        ['4100 Handling charge (Edus)', number_format($this->ledger->handlingRevenue(), 2)],
                    ]
                );
            } finally {
                $this->switcher->restore();
            }
        }

        $io->success(sprintf('%s — %d paid, %d skipped.', $simulate ? 'Simulated' : 'Done', $paid, $skipped));

        return Command::SUCCESS;
    }
}
