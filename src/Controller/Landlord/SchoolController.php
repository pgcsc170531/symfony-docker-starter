<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\School;
use App\Entity\Landlord\Plan;
use App\Entity\Landlord\Subscription;
use App\Service\SubscriptionService;
use App\Service\TenantProvisioner;
use App\Service\WalletService; // 🟢 1. IMPORT WALLET SERVICE
use App\Service\Payment\SchoolSubaccountService;
use App\Form\SchoolEditType;
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

    /**
     * Edit a school.
     *
     * The subdomain is deliberately not editable here: it is the tenant's address,
     * embedded in every report card, receipt, email and link the school has already
     * handed out. Renaming it is a migration, not a form field.
     */
    #[Route('/{id}/edit', name: 'landlord_school_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        School $school,
        Request $request,
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $em,
        SchoolSubaccountService $subaccounts,
    ): Response {
        $form = $this->createForm(SchoolEditType::class, $school);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = (string) $form->get('newPassword')->getData();
            if ($newPassword !== '') {
                $school->setPrincipalPassword(password_hash($newPassword, PASSWORD_BCRYPT));
            }

            $em->flush();

            $this->addFlash('success', sprintf('%s updated.', $school->getName()));

            return $this->redirectToRoute('landlord_school_show', ['id' => $school->getId()]);
        }

        // Counted, not guessed: the confirmation asks the operator to type the
        // subdomain, so the screen must show exactly how much is about to go.
        $subscribed = $em->getRepository(Subscription::class)->count(['school' => $school]);

        return $this->render('landlord/school/edit.html.twig', [
            'school' => $school,
            'form' => $form,
            'subscriptions' => $subscribed,
            'payments' => $subaccounts->details($school),
        ]);
    }

    /**
     * Create the Paystack subaccount this school's money settles into.
     *
     * Operator-only, and deliberately not a school-facing setting: Edus holds the
     * single Paystack account, so a school that could type its own ACCT_ code could
     * also point its fees at another school's bank account.
     */
    #[Route('/{id}/subaccount', name: 'landlord_school_subaccount', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createSubaccount(
        School $school,
        Request $request,
        SchoolSubaccountService $subaccounts,
    ): Response {
        if (!$this->isCsrfTokenValid('subaccount_' . $school->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That confirmation expired. Try again.');

            return $this->redirectToRoute('landlord_school_edit', ['id' => $school->getId()]);
        }

        $details = [
            'bank_name' => trim((string) $request->request->get('bank_name')),
            'account_number' => trim((string) $request->request->get('account_number')),
            'account_name' => trim((string) $request->request->get('account_name')),
        ];

        // Two buttons, one form: fix the bank details, or fix them and ask
        // Paystack for a subaccount in the same breath.
        if ($request->request->get('intent') === 'save') {
            $result = $subaccounts->saveBankDetails($school, $details);

            $this->addFlash(
                $result['ok'] ? 'success' : 'error',
                $result['ok'] ? 'Bank details saved.' : (string) ($result['message'] ?? 'Those details could not be saved.')
            );

            return $this->redirectToRoute('landlord_school_edit', ['id' => $school->getId()]);
        }

        $result = $subaccounts->create(
            $school,
            $details,
            trim((string) $request->request->get('bank_code')),
            (float) $request->request->get('percentage_charge', 0),
        );

        if ($result['ok']) {
            $this->addFlash('success', sprintf('Subaccount %s is set — parents at %s can now pay online.', $result['code'], $school->getName()));
        } else {
            $this->addFlash('error', (string) $result['message']);
        }

        return $this->redirectToRoute('landlord_school_edit', ['id' => $school->getId()]);
    }

    /**
     * Delete a school permanently.
     *
     * This is the destructive one: the record, its subscriptions/wallet/credit rows
     * and the tenant database itself go. Guarded three ways — POST only, a CSRF
     * token, and the operator typing the subdomain. "Deactivate" on the edit screen
     * is the reversible alternative and is offered right next to it.
     */
    #[Route('/{id}/delete', name: 'landlord_school_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        School $school,
        Request $request,
        ManagerRegistry $doctrine,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_school_' . $school->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That confirmation expired. Try again.');

            return $this->redirectToRoute('landlord_school_edit', ['id' => $school->getId()]);
        }

        $typed = trim((string) $request->request->get('confirm_subdomain'));
        if ($typed === '' || strcasecmp($typed, (string) $school->getSubdomain()) !== 0) {
            $this->addFlash('error', sprintf(
                'Nothing was deleted: type the subdomain "%s" to confirm.',
                (string) $school->getSubdomain()
            ));

            return $this->redirectToRoute('landlord_school_edit', ['id' => $school->getId()]);
        }

        $name = (string) $school->getName();
        $subdomain = (string) $school->getSubdomain();
        $database = (string) $school->getDatabaseName();

        $em = $doctrine->getManager('landlord');

        // Child rows first — the school row holds foreign keys they point at.
        foreach ([
            'App\Entity\Landlord\SubscriptionPayment' => 'school',
            'App\Entity\Landlord\TermRegistration' => 'school',
            'App\Entity\Landlord\StatementVerification' => 'school',
            'App\Entity\Landlord\WalletTransaction' => 'school',
            'App\Entity\Landlord\CreditRequest' => 'school',
            'App\Entity\Landlord\SupportTicket' => 'school',
            'App\Entity\Landlord\RegistrationInvite' => 'school',
            'App\Entity\Landlord\Subscription' => 'school',
        ] as $class => $field) {
            foreach ($em->getRepository($class)->findBy([$field => $school]) as $row) {
                $em->remove($row);
            }
        }

        $em->remove($school);
        $em->flush();

        // The database is dropped AFTER the record is gone, and a failure here is
        // reported rather than hidden: on a server whose app user has no DROP
        // privilege the school is still deleted, and the operator is told what is
        // left behind instead of being told it worked.
        $databaseDropped = false;
        if ($database !== '') {
            try {
                $em->getConnection()->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', str_replace('`', '', $database)));
                $databaseDropped = true;
            } catch (\Throwable) {
                // handled below
            }
        }

        if ($databaseDropped) {
            $this->addFlash('success', sprintf('%s (%s) deleted, and database %s dropped.', $name, $subdomain, $database));
        } else {
            $this->addFlash('warning', sprintf(
                '%s (%s) deleted, but database %s could not be dropped from here — drop it manually.',
                $name,
                $subdomain,
                $database
            ));
        }

        return $this->redirectToRoute('landlord_school_index');
    }
}