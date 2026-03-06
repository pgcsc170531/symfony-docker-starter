<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:landlord:update-schema',
    description: 'Updates the Landlord database schema directly (No migrations)',
)]
class UpdateLandlordSchemaCommand extends Command
{
    public function __construct(
        // We inject the Landlord Entity Manager directly
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Starting Landlord Schema Update...');

        try {
            // 1. Get Metadata specifically for Landlord entities
            $metadata = $this->landlordEm->getMetadataFactory()->getAllMetadata();

            if (empty($metadata)) {
                $io->warning('No entities found for Landlord. Check your configuration.');
                return Command::FAILURE;
            }

            $io->text(sprintf('Found %d entities mapped to Landlord.', count($metadata)));

            // 2. Create the SchemaTool using the Landlord EM
            $schemaTool = new SchemaTool($this->landlordEm);

            // 3. Get the SQL that WOUL be executed (just to show info)
            $sqls = $schemaTool->getUpdateSchemaSql($metadata, true); // true = save mode
            
            if (empty($sqls)) {
                $io->success('✅ Database is already in sync. No changes needed.');
                return Command::SUCCESS;
            }

            $io->section('Applying Changes...');
            
            // 4. Execute the Update
            // true = "Safe Mode" (it tries not to drop data, just update tables)
            $schemaTool->updateSchema($metadata, true);

            $io->success('✅ Landlord schema updated successfully!');

        } catch (\Exception $e) {
            $io->error('❌ Failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}