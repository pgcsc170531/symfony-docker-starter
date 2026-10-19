<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\School;
use App\Entity\Landlord\Plan;
use App\Entity\Landlord\Subscription;
use App\Service\SubscriptionService;
use App\Service\TenantProvisioner;
use App\Service\WalletService; // 🟢 1. IMPORT WALLET SERVICE
use App\Form\SchoolType;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/school')]
class SchoolController extends AbstractController
{
    #[Route('/new', name: 'app_landlord_school_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $entityManager,
        SubscriptionService $subscriptionService,
        TenantProvisioner $provisioner
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

            // 6. Build the tenant database: schema, session, term, grading scale, admin login.
            try {
                $provisioner->provision(
                    schoolName: $school->getName(),
                    subdomain: $school->getSubdomain(),
                    landlordSchoolId: $school->getId(),
                    principalName: $pName,
                    principalEmail: $pEmail,
                    principalPassword: $pPass,
                    databaseName: $dbName,
                );
            } catch (\Throwable $e) {
                $this->addFlash('error', sprintf(
                    "School '%s' was registered but its database could not be built: %s",
                    $dbName,
                    $e->getMessage()
                ));

                return $this->redirectToRoute('landlord_school_show', ['id' => $school->getId()]);
            }

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

    /**
     * Rebuild a school's tenant database (schema, settings row, session/term,
     * grading scale, principal login). Idempotent — `TenantProvisioner` only
     * creates what is missing — so it is safe on a healthy school and is the
     * recovery path for one whose provisioning failed half way.
     *
     * The principal's password is never reset here: provision() only creates the
     * user when the email does not exist.
     */
    #[Route('/{id}/fix-schema', name: 'app_landlord_school_fix_schema', methods: ['GET'])]
    public function fixSchema(School $school, TenantProvisioner $provisioner): Response
    {
        try {
            $result = $provisioner->provision(
                schoolName: (string) $school->getName(),
                subdomain: (string) $school->getSubdomain(),
                landlordSchoolId: $school->getId(),
                principalName: $school->getPrincipalName(),
                principalEmail: $school->getPrincipalEmail(),
                databaseName: $school->getDatabaseName(),
            );
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf(
                'Could not repair %s: %s',
                (string) $school->getDatabaseName(),
                $e->getMessage()
            ));

            return $this->redirectToRoute('landlord_school_show', ['id' => $school->getId()]);
        }

        $this->addFlash('success', sprintf(
            'Schema verified for %s%s — session %s, %s ready%s.',
            $result['database'],
            $result['database_created'] ? ' (database created)' : '',
            $result['session'],
            $result['term'],
            $result['admin_created'] !== null ? ', principal login created' : ''
        ));

        return $this->redirectToRoute('landlord_school_show', ['id' => $school->getId()]);
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