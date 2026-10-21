<?php

namespace App\Command;

use App\Entity\Landlord\School;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backfills the "owner is the super admin" rule for schools that were created
 * before the role existed.
 *
 * New schools get it from TenantProvisioner automatically. For an existing
 * school, the account provisioned at sign-up is the oldest administrator on
 * record — later administrators were created by it and therefore have higher
 * ids — so that is the account promoted by default. `--email` overrides the
 * guess for the rare school where that is wrong.
 *
 * Dry-run unless `--apply`, like `app:invoices:renumber`: this writes roles into
 * every tenant database and must not happen by accident.
 */
#[AsCommand(
    name: 'app:tenant:promote-owner',
    description: 'Marks the account created at sign-up as the school Super Admin (dry-run unless --apply)',
)]
class PromoteTenantOwnerCommand extends Command
{
    private const SUPER_ADMIN = 'ROLE_TENANT_SUPER_ADMIN';
    private const ADMIN = 'ROLE_ADMIN';

    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,

        #[Autowire(env: 'LANDLORD_DATABASE_URL')]
        private string $databaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes (otherwise this is a dry run)')
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this school, by subdomain')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Force this account, instead of the oldest administrator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $onlySubdomain = $input->getOption('school');
        $forcedEmail = $input->getOption('email');

        $io->title($apply ? 'Promoting school owners' : 'School owners (dry run — pass --apply to write)');

        $schools = $this->landlordEm->getRepository(School::class)->findAll();
        $changed = 0;
        $skipped = 0;

        foreach ($schools as $school) {
            if ($onlySubdomain !== null && $school->getSubdomain() !== $onlySubdomain) {
                continue;
            }

            $io->section(sprintf('%s (%s)', $school->getName(), $school->getSubdomain()));

            $databaseName = $school->getDatabaseName();
            if (!$databaseName) {
                $io->warning('No database name on the landlord record — skipped.');
                $skipped++;
                continue;
            }

            try {
                $connection = $this->tenantConnection($databaseName);
                $users = $connection->fetchAllAssociative('SELECT id, email, roles FROM `user` ORDER BY id ASC');
            } catch (\Throwable $e) {
                $io->warning('Could not read the tenant database: ' . $e->getMessage());
                $skipped++;
                continue;
            }

            $superAdmins = [];
            $admins = [];
            foreach ($users as $user) {
                $roles = json_decode((string) $user['roles'], true);
                $roles = is_array($roles) ? $roles : [];

                if (in_array(self::SUPER_ADMIN, $roles, true)) {
                    $superAdmins[] = $user['email'];
                } elseif (in_array(self::ADMIN, $roles, true)) {
                    $admins[] = $user;
                }
            }

            if ($superAdmins !== []) {
                $io->text(sprintf('Already has a super admin: %s', implode(', ', $superAdmins)));
                $skipped++;
                continue;
            }

            if ($admins === []) {
                $io->warning('No administrator account to promote.');
                $skipped++;
                continue;
            }

            $candidate = $admins[0];
            if ($forcedEmail !== null) {
                $match = null;
                foreach ($admins as $admin) {
                    if (strcasecmp((string) $admin['email'], $forcedEmail) === 0) {
                        $match = $admin;
                        break;
                    }
                }

                if ($match === null) {
                    $io->error(sprintf('%s is not an administrator in this school.', $forcedEmail));
                    $skipped++;
                    continue;
                }

                $candidate = $match;
            }

            if (count($admins) > 1) {
                $others = array_values(array_map(
                    static fn (array $u) => (string) $u['email'],
                    array_filter($admins, static fn (array $u) => $u['id'] !== $candidate['id'])
                ));
                $io->text(sprintf(
                    'Several administrators; promoting the oldest (id %d). Others: %s',
                    $candidate['id'],
                    implode(', ', $others)
                ));
            }

            $io->text(sprintf('→ %s (id %d)', $candidate['email'], $candidate['id']));

            if (!$apply) {
                $changed++;
                continue;
            }

            $roles = json_decode((string) $candidate['roles'], true);
            $roles = is_array($roles) ? $roles : [];
            $roles[] = self::SUPER_ADMIN;

            $connection->update(
                'user',
                ['roles' => json_encode(array_values(array_unique($roles)), JSON_THROW_ON_ERROR)],
                ['id' => $candidate['id']]
            );
            $connection->close();

            $io->success('Promoted.');
            $changed++;
        }

        $io->writeln(sprintf(
            '<info>%d school(s) %s, %d skipped.</info>',
            $changed,
            $apply ? 'updated' : 'would be updated',
            $skipped
        ));

        if (!$apply && $changed > 0) {
            $io->note('Nothing was written. Re-run with --apply to promote these accounts.');
        }

        return Command::SUCCESS;
    }

    private function tenantConnection(string $databaseName): Connection
    {
        $parts = parse_url($this->databaseUrl);
        if ($parts === false) {
            throw new \RuntimeException('LANDLORD_DATABASE_URL could not be parsed.');
        }

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => $parts['user'] ?? 'root',
            'password' => $parts['pass'] ?? '',
            'host' => $parts['host'] ?? 'database',
            'port' => $parts['port'] ?? 3306,
            'dbname' => $databaseName,
        ]);
    }
}
