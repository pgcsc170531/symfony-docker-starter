<?php

namespace App\Command;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\AccountCredit;
use App\Service\Payment\LedgerPoster;
use App\Service\Payment\PaystackClient;
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
 * Books Paystack's fee on every transfer that has not had it posted yet.
 *
 * Why a sweep rather than just the webhook: not every provider event carries the
 * fee, and a webhook that arrives without it would leave the books overstating our
 * margin forever. This asks Paystack for the figure (`GET /transaction/verify`) and
 * posts `5000 Gateway cost` / `1000 Cash`.
 *
 * Idempotent per credit: an entry already posted is skipped, and the ledger's
 * `(source_type, source_id)` uniqueness backs that up. A credit whose reference
 * Paystack does not know (a simulated one, or a transfer it has not indexed yet) is
 * reported and left for the next run — never estimated into the books silently.
 */
#[AsCommand(
    name: 'app:payments:gateway-costs',
    description: 'Post the bank/API fee for transfers whose cost is not in the ledger yet.'
)]
final class GatewayCostCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
        private readonly TenantConnectionSwitcher $switcher,
        private readonly PaystackClient $client,
        private readonly LedgerPoster $ledger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this school subdomain')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many credits to inspect per school', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be posted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $subdomain = (string) $input->getOption('school');
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $criteria = ['isActive' => true];
        if ($subdomain !== '') {
            $criteria['subdomain'] = $subdomain;
        }

        /** @var LandlordSchool[] $schools */
        $schools = $this->landlordEm->getRepository(LandlordSchool::class)->findBy($criteria);

        $posted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($schools as $school) {
            $this->switcher->switchTo($school);

            try {
                /** @var AccountCredit[] $credits */
                $credits = $this->tenantEm->getRepository(AccountCredit::class)->findBy(
                    [],
                    ['id' => 'DESC'],
                    $limit
                );

                foreach ($credits as $credit) {
                    $id = (int) $credit->getId();

                    if ($this->ledger->hasGatewayCost($id)) {
                        ++$skipped;
                        continue;
                    }

                    $reference = (string) $credit->getProviderReference();
                    $result = $this->client->fetchTransactionFees($reference);

                    if (!$result['ok'] || empty($result['feesMinor'])) {
                        ++$failed;
                        $io->writeln(sprintf(
                            '<comment>unknown</comment> %s  %s — %s',
                            $school->getSubdomain(),
                            $reference,
                            $result['message'] ?? 'no fee'
                        ));

                        continue;
                    }

                    if ($dryRun) {
                        $io->writeln(sprintf(
                            'would post %.2f for %s (%s)',
                            $result['feesMinor'] / 100,
                            $reference,
                            $school->getSubdomain()
                        ));

                        continue;
                    }

                    $this->ledger->postGatewayCost($id, $result['feesMinor'], $reference);
                    $this->tenantEm->flush();
                    ++$posted;

                    $io->writeln(sprintf(
                        '<info>posted</info> %s  %s  ₦%s',
                        $school->getSubdomain(),
                        $reference,
                        number_format($result['feesMinor'] / 100, 2)
                    ));
                }
            } finally {
                $this->switcher->restore();
            }
        }

        $io->success(sprintf(
            '%s — %d posted, %d already booked, %d without a fee from Paystack.',
            $dryRun ? 'Dry run' : 'Done',
            $posted,
            $skipped,
            $failed
        ));

        return Command::SUCCESS;
    }
}
