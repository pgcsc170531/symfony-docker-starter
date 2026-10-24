<?php

namespace App\Command;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\VirtualAccount;
use App\Service\Payment\LedgerPoster;
use App\Service\Payment\VirtualAccountCreditService;
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
 * Posts a **simulated** bank transfer through the real credit path, so the ledger
 * can be proven end to end without moving a naira.
 *
 * Why this exists: a live test needs a real transfer from a real bank app, which
 * is not always available (or safe) while we are still building. This command
 * builds the same payload Paystack's webhook would send, and hands it to
 * `VirtualAccountCreditService::ingest()` — the identical code path: idempotency,
 * intent matching ±₦1, invoice settlement, and the ledger postings.
 *
 * It is deliberately loud about what it is: the reference is prefixed `SIM-`, the
 * memo says "simulated", and nothing here can be mistaken for money that arrived.
 * Do not run it against a production tenant.
 */
#[AsCommand(
    name: 'app:payments:simulate-credit',
    description: 'Post a simulated transfer through the real credit + ledger path (no money moves).'
)]
final class SimulateCreditCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
        private readonly TenantConnectionSwitcher $switcher,
        private readonly VirtualAccountCreditService $credits,
        private readonly LedgerPoster $ledger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'School subdomain')
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Amount in naira, e.g. 65250')
            ->addOption('fees', null, InputOption::VALUE_REQUIRED, 'Provider fee in naira, as Paystack would report it', '0')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Reference for this simulated transfer (defaults to SIM-<time>)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $subdomain = (string) $input->getOption('school');
        $amount = (float) $input->getOption('amount');

        if ($subdomain === '' || $amount <= 0) {
            $io->error('Both --school and --amount are required.');

            return Command::INVALID;
        }

        $school = $this->landlordEm->getRepository(LandlordSchool::class)->findOneBy(['subdomain' => $subdomain]);

        if ($school === null) {
            $io->error(sprintf('No school with subdomain "%s".', $subdomain));

            return Command::FAILURE;
        }

        $this->switcher->switchTo($school);

        try {
            /** @var VirtualAccount|null $account */
            $account = $this->tenantEm->getRepository(VirtualAccount::class)->findOneBy(
                ['status' => VirtualAccount::STATUS_ACTIVE]
            );

            if ($account === null || $account->getPaystackCustomerCode() === null) {
                $io->error('This school has no active family account to credit. Ask a parent to request one first.');

                return Command::FAILURE;
            }

            $reference = (string) ($input->getOption('reference') ?: 'SIM-' . time());

            $payload = [
                'reference' => $reference,
                'amount' => (int) round($amount * 100),
                // Paystack includes its fee on the transaction; the ledger books it
                // as soon as it sees it, so the simulator can carry one too.
                'fees' => (int) round(((float) $input->getOption('fees')) * 100),
                'paid_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'customer' => ['customer_code' => $account->getPaystackCustomerCode()],
                'authorization' => [
                    'channel' => 'dedicated_nuban',
                    'sender_name' => 'SIMULATED SENDER',
                    'sender_bank' => 'SIMULATED BANK',
                    'customer_code' => $account->getPaystackCustomerCode(),
                ],
            ];

            $credit = $this->credits->ingest($payload);

            if ($credit === null) {
                $io->error('The credit path declined this payload.');

                return Command::FAILURE;
            }

            $io->writeln(sprintf('credit      #%s  %s  %s', $credit->getId(), $credit->getProviderReference(), $credit->getStatus()));

            $this->printBalances($io);
        } finally {
            $this->switcher->restore();
        }

        return Command::SUCCESS;
    }

    private function printBalances(SymfonyStyle $io): void
    {
        $io->table(
            ['Account', 'Balance'],
            [
                ['1000 Cash at Paystack', number_format($this->ledger->cashAtPaystack(), 2)],
                ['2000 School payable', number_format($this->ledger->schoolPayable(), 2)],
                ['2100 Unallocated', number_format($this->ledger->unallocated(), 2)],
                ['4100 Handling charge (Edus)', number_format($this->ledger->handlingRevenue(), 2)],
                ['5000 Gateway cost', number_format($this->ledger->gatewayCost(), 2)],
            ]
        );
    }
}
