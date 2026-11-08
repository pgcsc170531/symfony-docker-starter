<?php

namespace App\Command;

use App\Service\Geo\GeographySeeder;
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
 * Gives every tenant the Country / State / LGA rows the admission form needs.
 *
 * New schools get these automatically from {@see \App\Service\TenantProvisioner};
 * this command exists for schools provisioned before that step was added, and is
 * safe to re-run — it only inserts what is missing.
 *
 *   docker compose exec -T php php bin/console app:tenant:seed-nigeria
 *   docker compose exec -T php php bin/console app:tenant:seed-nigeria --school=gakprimary
 *
 * If a database predates the `country` / `state` / `lga` tables, run
 * `app:tenant:update-schema` first.
 */
#[AsCommand(
    name: 'app:tenant:seed-nigeria',
    description: 'Seeds the Nigerian Country, States and LGAs into every tenant database',
    aliases: ['app:tenant:seed-geography'],
)]
final class SeedNigeriaDataCommand extends TenantAwareCommand
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        Connection $tenantConnection,
        EntityManagerInterface $tenantEm,
        private readonly GeographySeeder $geography,
    ) {
        parent::__construct($landlordEm, $tenantConnection, $tenantEm);
    }

    protected function configure(): void
    {
        $this
            ->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this subdomain (default: every active school).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $schools = $this->schools((string) $input->getOption('school'));

        if ($schools === []) {
            $io->error('No active school matched that subdomain.');

            return Command::FAILURE;
        }

        $io->title('Seeding Nigerian Country, States and LGAs');

        $failed = 0;

        foreach ($schools as $school) {
            $io->section($school->getName() . ' (' . $school->getSubdomain() . ')');

            try {
                $this->switchTo($school);
                $result = $this->geography->seedNigeria($this->tenantEm);
            } catch (\Throwable $e) {
                $failed++;
                $io->error($e->getMessage());
                $io->note('If the geography tables are missing, run `bin/console app:tenant:update-schema` first.');
                continue;
            }

            $io->text(sprintf(' - Country: %s', $result['country_created'] ? 'created' : 'already present'));
            $io->text(sprintf(' - States added: %d', $result['states_added']));
            $io->text(sprintf(' - LGAs added: %d', $result['lgas_added']));
        }

        $this->tenantEm->clear();

        if ($failed > 0) {
            $io->warning(sprintf('%d school(s) could not be seeded. See the errors above.', $failed));

            return Command::FAILURE;
        }

        $io->success('Every school now has Nigeria, its 36 states + the FCT, and their LGAs.');

        return Command::SUCCESS;
    }
}
