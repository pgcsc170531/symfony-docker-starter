<?php

namespace App\Command;

use App\Entity\Landlord\School;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:tenant:verify-schema',
    description: 'Verifies Phase 1 + website columns exist in every tenant database',
)]
class VerifyTenantSchemaCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,
        #[Autowire(env: 'LANDLORD_DATABASE_URL')]
        private string $databaseUrl
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔎 Verifying Tenant Schema');

        $schools = $this->landlordEm->getRepository(School::class)->findAll();
        $parts = parse_url($this->databaseUrl);

        foreach ($schools as $school) {
            $io->section($school->getName() . " (" . $school->getSubdomain() . ")");

            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_mysql',
                'host'     => $parts['host'],
                'port'     => $parts['port'] ?? 3306,
                'user'     => $parts['user'],
                'password' => $parts['pass'],
                'dbname'   => $school->getDatabaseName(),
            ]);

            $tables = $connection->fetchFirstColumn('SHOW TABLES');
            $tables = array_map('strtolower', $tables);

            foreach (['department', 'subject', 'year_group'] as $t) {
                $ok = in_array($t, $tables, true);
                $io->writeln(sprintf('  %s %s', $ok ? '✅' : '❌', $t));
            }

            $cols = array_map('strtolower', $connection->fetchFirstColumn('SHOW COLUMNS FROM classroom'));
            $io->writeln(sprintf('  %s classroom.year_group_id', in_array('year_group_id', $cols, true) ? '✅' : '❌'));
            $io->writeln(sprintf('  %s classroom.arm', in_array('arm', $cols, true) ? '✅' : '❌'));

            $schoolCols = array_map('strtolower', $connection->fetchFirstColumn('SHOW COLUMNS FROM school_settings'));
            $io->writeln(sprintf('  %s school_settings.hero_tagline', in_array('hero_tagline', $schoolCols, true) ? '✅' : '❌'));

            $connection->close();
        }

        $io->success('Verification complete.');
        return Command::SUCCESS;
    }
}