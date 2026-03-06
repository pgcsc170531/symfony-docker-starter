<?php

namespace App\Command;

use App\Entity\Landlord\School;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:tenant:update-schema',
    description: 'Updates the database schema for all tenants (schools)',
)]
class UpdateTenantSchemaCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,

        // ✅ FIX: We tell Symfony exactly where to find this string
        #[Autowire(env: 'LANDLORD_DATABASE_URL')]
        private string $databaseUrl
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Starting Tenant Schema Update...');

        // 1. Get all Schools
        $schools = $this->landlordEm->getRepository(School::class)->findAll();
        $io->text(sprintf('Found %d schools to update.', count($schools)));

        // 2. Prepare the Tenant Config (Look in Tenant folder)
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Entity/Tenant'],
            isDevMode: true
        );

        // 3. Loop through every school
        foreach ($schools as $school) {
            $io->section("Updating: " . $school->getName() . " (" . $school->getSubdomain() . ")");

            try {
                // A. Parse the URL to get user/pass/host
                $parts = parse_url($this->databaseUrl);
                
                $params = [
                    'user'     => $parts['user'],
                    'password' => $parts['pass'],
                    'host'     => $parts['host'],
                    'port'     => $parts['port'] ?? 3306,
                    'driver'   => 'pdo_mysql',
                    'dbname'   => $school->getDatabaseName(),
                ];

                // B. Connect specifically to this Tenant DB
                $connection = DriverManager::getConnection($params, $config);
                $entityManager = new \Doctrine\ORM\EntityManager($connection, $config);

                // C. Run the Schema Update
                $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
                $schemaTool = new SchemaTool($entityManager);
                
                // true = Execute safely (update only, don't delete data)
                $schemaTool->updateSchema($metadata, true); 
                
                $io->success('✅ Schema updated successfully.');
                $connection->close();

            } catch (\Exception $e) {
                $io->error('❌ Failed: ' . $e->getMessage());
            }
        }

        $io->success('🎉 All tenant databases are up to date!');
        return Command::SUCCESS;
    }
}