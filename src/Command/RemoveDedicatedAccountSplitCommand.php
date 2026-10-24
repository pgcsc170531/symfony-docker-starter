<?php

namespace App\Command;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\VirtualAccount;
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
 * Removes the school split from dedicated accounts issued before we stopped
 * splitting (ONLINE-PAYMENT-LEDGER.md §6–§7).
 *
 * Why it matters: while an account still carries `split_config.subaccount`, every
 * transfer into it pays the **school** the parent's ₦650 as well as the fee, and
 * Edus receives nothing. This command closes that leak on accounts that already
 * exist; new accounts are created without a split.
 *
 * Safe to re-run: an account with no split comes back with an empty `split_config`,
 * and we simply report it as already clean.
 */
#[AsCommand(
    name: 'app:payments:remove-dva-split',
    description: 'Remove the school subaccount split from family accounts issued before the payout model.'
)]
final class RemoveDedicatedAccountSplitCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
        private readonly TenantConnectionSwitcher $switcher,
        private readonly PaystackClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this school subdomain')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->client->isConfigured()) {
            $io->error('No Paystack key is configured — nothing can be verified against the provider.');

            return Command::FAILURE;
        }

        $subdomain = $input->getOption('school');
        $dryRun = (bool) $input->getOption('dry-run');

        $criteria = ['isActive' => true];
        if (is_string($subdomain) && $subdomain !== '') {
            $criteria['subdomain'] = $subdomain;
        }

        /** @var LandlordSchool[] $schools */
        $schools = $this->landlordEm->getRepository(LandlordSchool::class)->findBy($criteria);

        $checked = 0;
        $cleaned = 0;
        $failed = 0;

        foreach ($schools as $school) {
            $this->switcher->switchTo($school);

            try {
                /** @var VirtualAccount[] $accounts */
                $accounts = $this->tenantEm->getRepository(VirtualAccount::class)->findBy([
                    'status' => VirtualAccount::STATUS_ACTIVE,
                ]);

                foreach ($accounts as $account) {
                    $number = (string) $account->getAccountNumber();

                    // The stored code is our own record of the split. Empty means
                    // this account was already issued under the no-split model.
                    if ($account->getSubaccountCode() === null || $number === '') {
                        continue;
                    }

                    ++$checked;

                    if ($dryRun) {
                        $io->writeln(sprintf('would remove split on %s (%s)', $number, $school->getName()));
                        continue;
                    }

                    $result = $this->client->removeDedicatedSplit($number);

                    if ($result['ok']) {
                        $account->setSubaccountCode(null);
                        ++$cleaned;
                        $io->writeln(sprintf('<info>split removed</info> %s (%s)', $number, $school->getName()));
                    } else {
                        ++$failed;
                        $io->writeln(sprintf(
                            '<error>failed</error> %s (%s): %s',
                            $number,
                            $school->getName(),
                            $result['message'] ?? 'unknown error'
                        ));
                    }
                }

                if (!$dryRun && $cleaned > 0) {
                    $this->tenantEm->flush();
                }
            } finally {
                $this->switcher->restore();
            }
        }

        if (!$dryRun) {
            $this->tenantEm->clear();
        }

        $io->success(sprintf(
            '%s — %d account(s) had a split, %d cleaned, %d failed.',
            $dryRun ? 'Dry run' : 'Done',
            $checked,
            $cleaned,
            $failed
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
