<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\School;
use App\Entity\Tenant\User;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\School as TenantSchool;
use App\Entity\Landlord\Plan;
use App\Entity\Landlord\Subscription;
use App\Service\SubscriptionService;
use App\Service\WalletService; // 🟢 1. IMPORT WALLET SERVICE
use App\Form\SchoolType;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin/school')]
class SchoolController extends AbstractController
{
    #[Route('/new', name: 'app_landlord_school_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        SubscriptionService $subscriptionService
    ): Response
    {
        $school = new School();
        $form = $this->createForm(SchoolType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            // 1. Capture Form Data
            $pName = $form->get('principalName')->getData();
            $pEmail = $form->get('principalEmail')->getData();
            $pPass = $form->get('principalPassword')->getData();

            // 2. Set DB Configuration
            $safeSubdomain = preg_replace('/[^a-z0-9]/', '', strtolower($school->getSubdomain()));
            $dbName = 'school_' . $safeSubdomain;
            
            $school->setDatabaseName($dbName);
            $school->setDbUser('root');
            $school->setDbPassword('root');
            $school->setDbHost('database');
            $school->setDbDriver('pdo_mysql');
            $school->setIsActive(true);

            // 3. Save Principal Details (Landlord DB)
            $school->setPrincipalName($pName);
            $school->setPrincipalEmail($pEmail);
            $school->setPrincipalPassword(password_hash($pPass, PASSWORD_BCRYPT));

            // 🟢 4. AUTO-ASSIGN FREE TRIAL LOGIC
            $trialPlan = $entityManager->getRepository(Plan::class)->findOneBy(['isTrial' => true]);
            
            if ($trialPlan) {
                $subscriptionService->createSubscription($school, $trialPlan, true);
            }

            // 5. Persist School
            $entityManager->persist($school);
            $entityManager->flush();

            // 6. Create Tenant DB & User
            $this->createTenantDatabaseAndSchema(
                $dbName, 
                $school->getName(), 
                $school->getSubdomain(),
                $pName, 
                $pEmail, 
                $pPass, 
                $passwordHasher,
                $school->getId()
            );

            $this->addFlash('success', "School '$dbName' created with Free Trial activated!");
            return $this->redirectToRoute('landlord_school_index'); 
        }

        return $this->render('landlord/school/new.html.twig', [
            'school' => $school,
            'form' => $form,
        ]);
    }

    // ======================================================
    // 🟢 2. MANUAL WALLET TOP-UP (NEW)
    // ======================================================
    #[Route('/{id}/top-up', name: 'landlord_school_top_up', methods: ['POST'])]
    public function topUp(
        School $school, 
        Request $request, 
        WalletService $walletService,
        ManagerRegistry $doctrine
    ): Response
    {
        $amount = (float) $request->request->get('amount');
        $note = $request->request->get('note');

        if ($amount <= 0) {
            $this->addFlash('error', 'Amount must be greater than 0.');
            return $this->redirectToRoute('landlord_school_show', ['id' => $school->getId()]);
        }

        // 1. Use Service to Add Credit
        $walletService->addCredit(
            $school, 
            $amount, 
            $note ?: 'Manual Top-up by Admin', 
            'MANUAL-' . uniqid()
        );

        // 2. Flush
        $em = $doctrine->getManager('landlord');
        $em->flush();

        $this->addFlash('success', "Successfully added ₦" . number_format($amount) . " to the wallet.");
        
        return $this->redirectToRoute('landlord_school_show', ['id' => $school->getId()]);
    }

    private function createTenantDatabaseAndSchema(
        string $dbName, 
        string $schoolName,
        string $subdomain,
        string $principalName, 
        string $principalEmail,
        string $principalPassword,
        UserPasswordHasherInterface $hasher,
        int $landlordSchoolId
    ): void
    {
        $connectionParams = [
            'user' => 'root', 'password' => 'root', 'host' => 'database', 'driver' => 'pdo_mysql',
        ];

        $tmpConnection = DriverManager::getConnection($connectionParams);
        $schemaManager = $tmpConnection->createSchemaManager();

        if (!in_array($dbName, $schemaManager->listDatabases())) {
            $schemaManager->createDatabase($dbName);
        }
        $tmpConnection->close();

        // Connect to New Database
        $tenantParams = array_merge($connectionParams, ['dbname' => $dbName]);
        
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../Entity/Tenant'],
            isDevMode: true
        );
        
        $tenantConnection = DriverManager::getConnection($tenantParams, $config);
        $tenantEntityManager = new \Doctrine\ORM\EntityManager($tenantConnection, $config);

        // Create Tables
        $metadata = $tenantEntityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($tenantEntityManager);
        $schemaTool->updateSchema($metadata, true);

        // Seed Data
        try {
            $currentYear = date('Y');
            $nextYear = $currentYear + 1;
            
            $session = new Session();
            $session->setName("$currentYear/$nextYear");
            $session->setIsActive(true);
            $tenantEntityManager->persist($session);

            $term = new Term();
            $term->setName('First Term');
            $term->setIsActive(true);
            $term->setSession($session);
            $tenantEntityManager->persist($term);

            $tenantSchool = new TenantSchool();
            $tenantSchool->setName($schoolName);
            $tenantSchool->setEmail($principalEmail);
            $tenantSchool->setSubdomain($subdomain); 
            $tenantSchool->setLandlordSchoolId($landlordSchoolId);
            $tenantSchool->setPrimaryColor('#4f46e5'); 
            $tenantEntityManager->persist($tenantSchool);

            $user = new User();
            $user->setEmail($principalEmail);
            $user->setFullName($principalName);
            $user->setRoles(['ROLE_ADMIN', 'ROLE_BURSAR']);
            $user->setSchool($tenantSchool); 

            $hashedPassword = $hasher->hashPassword($user, $principalPassword);
            $user->setPassword($hashedPassword);
            
            $tenantEntityManager->persist($user);
            $tenantEntityManager->flush();
            
        } catch (\Exception $e) {
            // Log error
        }

        $tenantConnection->close();
    }

    #[Route('/{id}/fix-schema', name: 'app_landlord_school_fix_schema', methods: ['GET'])]
    public function fixSchema(School $school): Response
    {
        $this->addFlash('warning', "Schema fix not fully implemented for new args yet.");
        return $this->redirectToRoute('app_landlord_school_new');
    }

    #[Route('/', name: 'landlord_school_index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        $schools = $doctrine->getManager('landlord')
            ->getRepository(School::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return $this->render('landlord/school/index.html.twig', [
            'schools' => $schools,
        ]);
    }

    #[Route('/{id}', name: 'landlord_school_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(School $school): Response
    {
        return $this->render('landlord/school/show.html.twig', [
            'school' => $school,
        ]);
    }
}