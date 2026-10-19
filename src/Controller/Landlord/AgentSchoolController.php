<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\School;
use App\Entity\Landlord\Agent;
use App\Entity\Landlord\Plan; // 👈 Added
use App\Form\SchoolType;
use App\Service\SubscriptionService; // 👈 Added
use App\Service\TenantProvisioner;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/agent/school')]
#[IsGranted('ROLE_AGENT')] 
class AgentSchoolController extends AbstractController
{
    // ... (index and show methods remain the same) ...

    #[Route('/', name: 'app_agent_school_index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        /** @var Agent $agent */
        $agent = $this->getUser();

        $schools = $doctrine->getManager('landlord')
            ->getRepository(School::class)
            ->findBy(['agent' => $agent]);

        return $this->render('landlord/agent/school/index.html.twig', [
            'schools' => $schools,
        ]);
    }

    #[Route('/{id}', name: 'app_agent_school_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ManagerRegistry $doctrine): Response 
    {
        $school = $doctrine->getManager('landlord')
            ->getRepository(School::class)
            ->find($id);

        if (!$school) {
            throw $this->createNotFoundException('School not found');
        }

        if ($school->getAgent() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You do not have permission to manage this school.');
        }

        return $this->render('landlord/agent/school/show.html.twig', [
            'school' => $school,
        ]);
    }

    // ======================================================
    // 🟢 3. CREATE SCHOOL (UPDATED WITH TRIAL LOGIC)
    // ======================================================
    #[Route('/new', name: 'app_agent_school_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionService $subscriptionService, // 🟢👈 INJECT SERVICE
        TenantProvisioner $provisioner
    ): Response
    {
        $school = new School();
        
        /** @var Agent $agent */
        $agent = $this->getUser();
        $school->setAgent($agent);

        $form = $this->createForm(SchoolType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $landlordEm = $doctrine->getManager('landlord');

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

            // 3. SAVE PRINCIPAL DETAILS
            $school->setPrincipalName($pName);
            $school->setPrincipalEmail($pEmail);

            // 4. HASH PASSWORD
            $hashedForLandlord = password_hash($pPass, PASSWORD_BCRYPT);
            $school->setPrincipalPassword($hashedForLandlord);

            // 🟢 5. NEW: AUTO-ASSIGN FREE TRIAL
            $trialPlan = $landlordEm->getRepository(Plan::class)->findOneBy(['isTrial' => true]);
            
            if ($trialPlan) {
                // This service handles creating the Subscription + Calculating the 14 days
                $subscriptionService->createSubscription($school, $trialPlan, true);
            }
            // 🟢 END TRIAL LOGIC

            // 6. Save Landlord Record
            // (Note: The SubscriptionService flushes, but we ensure the school is persisted here too)
            $landlordEm->persist($school);
            $landlordEm->flush();

            // 7. Build the tenant database: schema, session, term, grading scale, admin login.
            try {
                $this->provisioner->provision(
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

                return $this->redirectToRoute('app_agent_school_show', ['id' => $school->getId()]);
            }

            $this->addFlash('success', "Success! School '$dbName' has been enrolled with a Free Trial.");
            return $this->redirectToRoute('app_agent_school_index');
        }

        return $this->render('landlord/agent/school/new.html.twig', [
            'school' => $school,
            'form' => $form,
        ]);
    }

}