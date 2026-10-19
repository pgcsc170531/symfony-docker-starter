<?php

namespace App\Command;

use App\Entity\Landlord\Plan;
use App\Entity\Landlord\School;
use App\Service\SubscriptionService;
use App\Service\TenantProvisioner;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:landlord:create-school',
    description: 'Registers a new school and brings its tenant database online',
)]
class CreateSchoolCommand extends Command
{
    public function __construct(
        private ManagerRegistry $registry,
        private TenantProvisioner $provisioner,
        private SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'School Name')
            ->addArgument('subdomain', InputArgument::REQUIRED, 'Subdomain')
            ->addArgument('db_name', InputArgument::OPTIONAL, 'Database Name (defaults to school_<subdomain>)')
            ->addArgument('db_user', InputArgument::OPTIONAL, 'DB User', 'root')
            ->addArgument('db_pass', InputArgument::OPTIONAL, 'DB Password', 'root')
            ->addArgument('db_host', InputArgument::OPTIONAL, 'DB Host', 'database')
            ->addOption('principal-name', null, InputOption::VALUE_REQUIRED, 'Principal full name (also creates the admin login)')
            ->addOption('principal-email', null, InputOption::VALUE_REQUIRED, 'Principal email (also creates the admin login)')
            ->addOption('principal-password', null, InputOption::VALUE_REQUIRED, 'Principal password', TenantProvisioner::DEFAULT_PRINCIPAL_PASSWORD)
            ->addOption('landlord-only', null, InputOption::VALUE_NONE, 'Register the record only, without building the tenant database')
            ->setHelp(<<<'HELP'
Registers the school in the landlord database and then builds everything it needs
to operate: the tenant database, every table, the active session and first term,
the A1-F9 grading scale and (optionally) the principal's login.

  <info>php bin/console app:landlord:create-school "Royal Academy" royal \
      --principal-name "Musa Ibrahim" --principal-email head@royal.ng</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = (string) $input->getArgument('name');
        $subdomain = (string) $input->getArgument('subdomain');

        /** @var \Doctrine\ORM\EntityManagerInterface|null $em */
        $em = $this->registry->getManagerForClass(School::class);
        if (!$em) {
            $io->error('Could not find the Landlord Entity Manager. Check doctrine.yaml');
            return Command::FAILURE;
        }

        // The landlord subdomain decides the database name, sanitised exactly the
        // way the landlord UI does it, so both onboarding paths agree.
        $safeSubdomain = (string) preg_replace('/[^a-z0-9]/', '', strtolower($subdomain));
        if ($safeSubdomain === '') {
            $io->error('Subdomain must contain at least one letter or digit.');
            return Command::FAILURE;
        }

        $clash = $em->getRepository(School::class)->findOneBy(['subdomain' => $safeSubdomain]);
        if ($clash !== null) {
            $io->error(sprintf('Subdomain "%s" is already used by "%s".', $safeSubdomain, $clash->getName()));
            return Command::FAILURE;
        }

        $principalName = $input->getOption('principal-name');
        $principalEmail = $input->getOption('principal-email');

        $school = new School();
        $school->setName($name);
        $school->setSubdomain($safeSubdomain);
        $school->setDatabaseName((string) ($input->getArgument('db_name') ?: $this->provisioner->databaseNameFor($safeSubdomain)));
        $school->setDbUser((string) $input->getArgument('db_user'));
        $school->setDbPassword((string) $input->getArgument('db_pass'));
        $school->setDbHost((string) $input->getArgument('db_host'));
        $school->setDbDriver('pdo_mysql');
        $school->setIsActive(true);

        // Keep the landlord record in step with the principal we are about to create.
        // `principal_password` is NOT NULL in the landlord schema, so it is always set.
        $school->setPrincipalName($principalName ?: $name . ' Principal');
        $school->setPrincipalEmail($principalEmail ?: '');
        $school->setPrincipalPassword(password_hash(
            (string) $input->getOption('principal-password'),
            PASSWORD_BCRYPT
        ));

        $em->persist($school);
        $em->flush();
        $io->text(sprintf('Registered %s (id %d).', $name, $school->getId()));

        // Free trial, exactly like the landlord UI does.
        try {
            $trialPlan = $em->getRepository(Plan::class)->findOneBy(['isTrial' => true]);
            if ($trialPlan !== null) {
                $this->subscriptionService->createSubscription($school, $trialPlan, true);
                $em->flush();
                $io->text('Free trial subscription activated.');
            }
        } catch (\Throwable $e) {
            $io->warning('Subscription not created: ' . $e->getMessage());
        }

        if ($input->getOption('landlord-only')) {
            $io->success('Landlord record created. Tenant database was NOT built (--landlord-only).');
            return Command::SUCCESS;
        }

        try {
            $result = $this->provisioner->provision(
                schoolName: $name,
                subdomain: $safeSubdomain,
                landlordSchoolId: $school->getId(),
                principalName: $principalName,
                principalEmail: $principalEmail,
                principalPassword: $input->getOption('principal-password'),
            );
        } catch (\Throwable $e) {
            $io->error(sprintf(
                'School "%s" was registered but its database could not be built: %s',
                $name,
                $e->getMessage()
            ));
            $io->note('Fix the cause and re-run the command - provisioning is idempotent.');

            return Command::FAILURE;
        }

        $io->table(
            ['Item', 'Value'],
            [
                ['Database', $result['database'] . ($result['database_created'] ? ' (created)' : ' (reused)')],
                ['School settings', $result['school_created'] ? 'created' : 'already existed'],
                ['Academic session', $result['session']],
                ['Current term', $result['term']],
                ['Grading scale', $result['grading_scale_seeded'] . ' grade(s) added'],
                ['Admin login', $result['admin_created'] ?? 'not created (no --principal-email)'],
            ]
        );

        $io->success(sprintf('%s is online at http://%s.localhost:8082', $name, $safeSubdomain));
        if ($result['admin_created'] !== null) {
            $io->note(sprintf(
                'Principal password: %s - ask them to change it after the first login.',
                (string) $input->getOption('principal-password')
            ));
        }

        return Command::SUCCESS;
    }
}