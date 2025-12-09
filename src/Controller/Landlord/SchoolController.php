<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\School;
use App\Form\SchoolType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool; // <--- This is the tool that creates tables!
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\DriverManager;

#[Route('/landlord/school')]
class SchoolController extends AbstractController
{
    #[Route('/new', name: 'app_landlord_school_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $entityManager
    ): Response
    {
        $school = new School();
        $form = $this->createForm(SchoolType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 1. Set DB Name
            $dbName = 'school_' . strtolower($school->getSubdomain());
            $school->setDatabaseName($dbName);

            // 2. Save School Record
            $entityManager->persist($school);
            $entityManager->flush();

            // 3. Create Database AND Tables (The full setup)
            $this->createTenantDatabaseAndSchema($dbName);

            $this->addFlash('success', 'School created and Database tables generated successfully!');
            return $this->redirectToRoute('app_landlord_school_new');
        }

        return $this->render('landlord/school/new.html.twig', [
            'school' => $school,
            'form' => $form,
        ]);
    }

    private function createTenantDatabaseAndSchema(string $dbName): void
    {
        // --- STEP 1: Connect to Server and Create Database ---
        $url = $_ENV['LANDLORD_DATABASE_URL'];
        $parts = parse_url($url);
        
        $rootParams = [
            'user'     => $parts['user'],
            'password' => $parts['pass'],
            'host'     => $parts['host'],
            'port'     => $parts['port'] ?? 3306,
            'driver'   => 'pdo_mysql',
        ];

        $rootConn = DriverManager::getConnection($rootParams);
        $schemaManager = $rootConn->createSchemaManager();

        if (!in_array($dbName, $schemaManager->listDatabases())) {
            $schemaManager->createDatabase($dbName);
        }
        $rootConn->close();

        // --- STEP 2: Create Tables (Student, etc.) ---
        
        // Connect to the NEW database
        $tenantParams = $rootParams;
        $tenantParams['dbname'] = $dbName;

        // Tell Doctrine: "Look inside src/Entity/Tenant for table definitions"
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../Entity/Tenant'], 
            isDevMode: true
        );

        $tenantConn = DriverManager::getConnection($tenantParams, $config);
        $tenantEm = new EntityManager($tenantConn, $config);
        
        // Get the metadata (This finds your Student class!)
        $metadata = $tenantEm->getMetadataFactory()->getAllMetadata();
        
        // Run the SQL to create tables
        $schemaTool = new SchemaTool($tenantEm);
        $schemaTool->updateSchema($metadata);
        
        $tenantConn->close();
    }
}