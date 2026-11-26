<?php

namespace App\Command;

use App\Entity\Tenant\User;
use App\Service\Staff\StaffParentLinker;
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
 * "How many people are we holding twice?"
 *
 * A school that imported its parents and then registered the teachers through the
 * form often has two logins for one person — the parent one and the staff one —
 * because neither existed when the other was created. This lists them, read-only,
 * so the office knows the size of the job before touching anything.
 *
 * The join itself is never done here: `/staff/{id}/edit` → "Also a parent here?"
 * does it with the office watching, and `app:tenant:link-staff-login` does it in
 * bulk with a dry run.
 */
#[AsCommand(
    name: 'app:tenant:find-split-logins',
    description: 'Lists staff logins and separate parent logins that look like the same person (read-only)',
)]
final class FindSplitLoginsCommand extends TenantAwareCommand
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        Connection $tenantConnection,
        EntityManagerInterface $tenantEm,
        private readonly StaffParentLinker $linker,
    ) {
        parent::__construct($landlordEm, $tenantConnection, $tenantEm);
    }

    protected function configure(): void
    {
        $this->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this school, by subdomain');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 Staff logins with a separate parent login');

        $schools = $this->schools((string) $input->getOption('school'));

        if ($schools === []) {
            $io->error('No active school matched.');

            return Command::FAILURE;
        }

        $total = 0;

        foreach ($schools as $school) {
            $this->switchTo($school);

            $users = $this->tenantEm->getRepository(User::class)->findAll();

            $rows = [];

            foreach ($users as $staff) {
                if (!$staff->holdsStaffRole()) {
                    continue;
                }

                $phone = (string) $staff->getStaff()?->getPhone();
                $email = (string) $staff->getEmail();

                foreach ($this->linker->candidates($staff, $phone, $email) as $parent) {
                    $family = $this->tenantEm->getRepository(\App\Entity\Tenant\Guardian::class)
                        ->findOneBy(['user' => $parent]);

                    $rows[] = [
                        (string) $staff->getEmail(),
                        $phone !== '' ? $phone : '—',
                        (string) $parent->getEmail(),
                        $family?->getPhoneNumber() ?: '—',
                        (string) ($family?->getStudents()->count() ?? 0),
                    ];
                }
            }

            $io->section(sprintf('%s (%s)', $school->getName(), $school->getSubdomain()));

            if ($rows === []) {
                $io->text('  ✓ No split logins found.');

                continue;
            }

            $total += count($rows);

            $io->table(
                ['Staff login', 'Staff phone', 'Parent login', 'Family phone', 'Children'],
                $rows
            );
        }

        $io->newLine();

        if ($total === 0) {
            $io->success('Every staff member has a single login.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d pair(s) look like the same person with two logins.', $total));
        $io->text('Join them one at a time from Staff → the person → "Also a parent here?",');
        $io->text('or in bulk: php bin/console app:tenant:link-staff-login --school=… --staff=… --parent=… [--apply]');

        return Command::SUCCESS;
    }
}
