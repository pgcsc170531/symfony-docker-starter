<?php

namespace App\Command;

use App\Entity\Tenant\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:tenant:create-user',
    description: 'Creates a new user in the current tenant database',
)]
class CreateTenantUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email of the user')
            ->addArgument('password', InputArgument::REQUIRED, 'The password')
            ->addArgument('role', InputArgument::OPTIONAL, 'The role (admin, bursar, store)', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $roleInput = $input->getArgument('role');

        // Map simple names to Symfony Roles
        $roleMap = [
            'admin' => 'ROLE_ADMIN',
            'bursar' => 'ROLE_BURSAR',
            'store' => 'ROLE_STORE',
            'parent' => 'ROLE_PARENT'
        ];
        
        $role = $roleMap[$roleInput] ?? 'ROLE_USER';

        $user = new User();
        $user->setEmail($email);
        $user->setFullName(ucfirst($roleInput) . " User");
        $user->setRoles([$role]);

        // Hash the password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln("✅ Success! Created user: $email with role: $role");
        $output->writeln("👉 You can now log in at /login");

        return Command::SUCCESS;
    }
}