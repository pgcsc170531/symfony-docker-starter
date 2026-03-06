<?php

namespace App\Command;

use App\Entity\Landlord\School;
use Doctrine\Persistence\ManagerRegistry; // <--- CHANGED THIS
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:landlord:create-school',
    description: 'Registers a new school in the Landlord Database',
)]
class CreateSchoolCommand extends Command
{
    // Inject ManagerRegistry instead of EntityManagerInterface
    public function __construct(private ManagerRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'School Name')
            ->addArgument('subdomain', InputArgument::REQUIRED, 'Subdomain')
            ->addArgument('db_name', InputArgument::REQUIRED, 'Database Name')
            ->addArgument('db_user', InputArgument::OPTIONAL, 'DB User', 'root')
            ->addArgument('db_pass', InputArgument::OPTIONAL, 'DB Password', 'root')
            ->addArgument('db_host', InputArgument::OPTIONAL, 'DB Host', 'database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $school = new School();
        $school->setName($input->getArgument('name'));
        $school->setSubdomain($input->getArgument('subdomain'));
        $school->setDatabaseName($input->getArgument('db_name'));
        
        $school->setDbUser($input->getArgument('db_user'));
        $school->setDbPassword($input->getArgument('db_pass'));
        $school->setDbHost($input->getArgument('db_host'));
        $school->setDbDriver('pdo_mysql');
        $school->setIsActive(true);

        // MAGIC FIX: Get the specific manager for the School entity (Landlord DB)
        $em = $this->registry->getManagerForClass(School::class);
        
        if (!$em) {
            $output->writeln('<error>Could not find the Landlord Entity Manager. Check doctrine.yaml</error>');
            return Command::FAILURE;
        }

        $em->persist($school);
        $em->flush();

        $output->writeln("✅ School Registered Successfully!");
        
        return Command::SUCCESS;
    }
}