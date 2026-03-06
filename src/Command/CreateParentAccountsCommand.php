<?php

namespace App\Command;

use App\Entity\Landlord\School; // ✅ Correct Import
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:parents:create-accounts',
    description: 'Creates user accounts for existing parents (Fixed for School Entity)',
)]
class CreateParentAccountsCommand extends Command
{
    public function __construct(
        private ManagerRegistry $registry,
        private UserPasswordHasherInterface $hasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 1. Get Landlord Manager to find all Schools
        $landlordManager = $this->registry->getManager('landlord');
        
        // ✅ Use 'School' class, not 'Tenant'
        $schools = $landlordManager->getRepository(School::class)->findAll();

        if (empty($schools)) {
            $io->warning("No schools found in the Landlord Database.");
            return Command::FAILURE;
        }

        $io->info(sprintf('Found %d schools. Starting parent account generation...', count($schools)));

        foreach ($schools as $school) {
            $io->section("Processing School: " . $school->getName());

            // 2. GET DATABASE NAME
            // ✅ We use the getter from your entity
            $dbName = $school->getDatabaseName(); 

            // 3. FORCE DATABASE SWITCH
            $this->switchDatabaseConnection($dbName, $io);

            // 4. PROCESS STUDENTS
            $this->processTenant($io);
        }

        $io->success('All schools processed.');
        return Command::SUCCESS;
    }

    private function switchDatabaseConnection(string $dbName, SymfonyStyle $io): void
    {
        $connection = $this->registry->getConnection('default');
        $entityManager = $this->registry->getManager('default');

        if ($connection->isConnected()) {
            $connection->close();
        }

        $ref = new \ReflectionObject($connection);
        if ($ref->hasProperty('params')) {
            $paramsProp = $ref->getProperty('params');
            $paramsProp->setAccessible(true);
            $params = $paramsProp->getValue($connection);
            
            $params['dbname'] = $dbName;
            
            $paramsProp->setValue($connection, $params);
            
            $io->text(" > Switching connection to database: <info>$dbName</info>");
        } else {
            $io->error("Could not switch database: 'params' property not found.");
        }

        $entityManager->clear();
    }

   private function processTenant(SymfonyStyle $io): void
    {
        $em = $this->registry->getManager('default');
        
        try {
            $students = $em->getRepository(Student::class)->findAll();
        } catch (\Exception $e) {
            $io->error("Failed to query 'Student' table. Error: " . $e->getMessage());
            return;
        }

        $count = 0;
        foreach ($students as $student) {
            // 1. Get the Guardian Object
            $guardian = $student->getGuardian();

            // 2. Skip if no Guardian or Guardian has no Email
            // Note: I am guessing your Guardian entity has getEmail(). 
            // If it is getEmailAddress(), please adjust below.
            if (!$guardian || !$guardian->getEmail()) {
                continue;
            }
            
            // 3. Check if User already exists
            $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $guardian->getEmail()]);

            if (!$existingUser) {
                $user = new User();
                $user->setEmail($guardian->getEmail());
                
                // Try to get a name, or fallback to 'Parent'
                // Assuming Guardian has getFirstName() and getLastName()
                $name = 'Parent';
                if (method_exists($guardian, 'getFullName')) {
                    $name = $guardian->getFullName();
                } elseif (method_exists($guardian, 'getFirstName')) {
                     $name = $guardian->getFirstName() . ' ' . $guardian->getLastName();
                }

                $user->setFullName($name);
                $user->setRoles(['ROLE_PARENT']);
                
                // Password = Phone OR '123456'
                // Assuming Guardian has getPhoneNumber() or getPhone()
                $rawPassword = '123456';
                if (method_exists($guardian, 'getPhoneNumber')) {
                    $rawPassword = $guardian->getPhoneNumber() ?? '123456';
                } elseif (method_exists($guardian, 'getPhone')) {
                    $rawPassword = $guardian->getPhone() ?? '123456';
                }

                $user->setPassword($this->hasher->hashPassword($user, $rawPassword));

                // Link the User back to the Student or Guardian if your schema supports it
                // (Optional depending on your User entity structure)

                $em->persist($user);
                $count++;
            }
        }

        $em->flush();
        $io->text(" > Created <info>$count</info> new parent accounts.");
    }
}