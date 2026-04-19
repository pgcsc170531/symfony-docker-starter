<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\State;
use App\Entity\Tenant\FeeStructure; 
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\School as TenantSchool;
use App\Form\GuardianType;
use App\Form\StudentAdmissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\NotificationService; 
use App\Service\Finance\DiscountCalculator; // 🟢 ADDED FOR AUTOMATED BILLING
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\Persistence\ManagerRegistry;

#[Route('/admission')]
class AdmissionController extends AbstractController
{
    #[Route('/', name: 'app_tenant_admission_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $searchQuery = $request->query->get('q');
        $foundGuardian = null;

        if ($searchQuery) {
            $foundGuardian = $em->getRepository(Guardian::class)->findOneBy(['phoneNumber' => $searchQuery]);
            if (!$foundGuardian) {
                $foundGuardian = $em->getRepository(Guardian::class)->findOneBy(['email' => $searchQuery]);
            }
        }

        $guardian = new Guardian();
        $form = $this->createForm(GuardianType::class, $guardian);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $email = $form->get('email')->getData();
            $phone = $form->get('phoneNumber')->getData();

            $existingGuardian = $em->getRepository(Guardian::class)->findOneBy(['email' => $email]);
            if (!$existingGuardian) {
                $existingGuardian = $em->getRepository(Guardian::class)->findOneBy(['phoneNumber' => $phone]);
            }

            if ($existingGuardian) {
                $this->addFlash('info', 'Parent profile found! Redirecting to add child...');
                return $this->redirectToRoute('app_tenant_admission_register', ['id' => $existingGuardian->getId()]);
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $user = new User();
            $user->setEmail($guardian->getEmail());
            $user->setFullName($guardian->getFullName());
            $user->setRoles(['ROLE_PARENT']);
            $user->setPassword($hasher->hashPassword($user, 'parent'));

            $em->persist($user);
            $guardian->setUser($user);
            $em->persist($guardian);
            $em->flush();

            $this->addFlash('success', 'New Parent Profile Created!');
            return $this->redirectToRoute('app_tenant_admission_register', ['id' => $guardian->getId()]);
        }

        return $this->render('tenant/admission/index.html.twig', [
            'form' => $form->createView(),
            'foundGuardian' => $foundGuardian,
            'searchQuery' => $searchQuery
        ]);
    }

    #[Route('/register/{id}', name: 'app_tenant_admission_register', methods: ['GET', 'POST'])]
    public function register(
        Guardian $guardian, 
        Request $request, 
        EntityManagerInterface $em,
        NotificationService $notifier, 
        ManagerRegistry $doctrine,
        DiscountCalculator $discountCalculator // 🟢 INJECTED SERVICE
    ): Response {
        $student = new Student();
        $student->setGuardian($guardian); 
        $names = explode(' ', $guardian->getFullName());
        $student->setLastName($names[count($names)-1] ?? '');

        $form = $this->createForm(StudentAdmissionType::class, $student);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $admissionSuccess = $em->wrapInTransaction(function($em) use ($student, $discountCalculator) {
                
                // ========================================================
                // 1. AUTO-GENERATE ADMISSION NUMBER
                // ========================================================
                if (!$student->getAdmissionNumber()) {
                    $currentYear = date('Y');
                    $lastStudent = $em->getRepository(Student::class)->findOneBy([], ['id' => 'DESC']);
                    $nextSequence = $lastStudent ? ($lastStudent->getId() + 1) : 1;
                    $paddedSequence = str_pad((string)$nextSequence, 4, '0', STR_PAD_LEFT);
                    $student->setAdmissionNumber(sprintf('ADM/%s/%s', $currentYear, $paddedSequence));
                }

                $em->persist($student);

                // ========================================================
                // 2. AUTOMATED ENROLLMENT & ACADEMIC BILLING
                // ========================================================
                $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
                $classroom = $student->getCurrentClassroom();

                if ($activeTerm && $classroom) {
                    $session = $activeTerm->getSession();

                    // A. Create Official Enrollment Record
                    $enrollment = new Enrollment();
                    $enrollment->setStudent($student);
                    $enrollment->setSession($session);
                    $enrollment->setClassroom($classroom);
                    $enrollment->setEnrolledAt(new \DateTimeImmutable());
                    $em->persist($enrollment);

                    // B. Fetch Fees for this Class
                    $fees = $em->getRepository(FeeStructure::class)->findBy([
                        'classroom' => $classroom,
                        'term' => $activeTerm
                    ]);

                    if (count($fees) > 0) {
                        // C. Generate Academic Invoice
                        $invoice = new Invoice();
                        $invoice->setStudent($student);
                        $invoice->setTerm($activeTerm);
                        $invoice->setSession($session);
                        $invoice->setClassroom($classroom);
                        $invoice->setInvoiceNumber('INV-' . date('Y') . '-' . uniqid());
                        $invoice->setStatus('UNPAID');
                        $invoice->setType('ACADEMIC'); // 🟢 Flagged as Academic Fee
                        $invoice->setPaidAmount("0");
                        $invoice->setCreatedAt(new \DateTimeImmutable());

                        $total = 0;
                        foreach ($fees as $fee) {
                            if (!$fee->getFeeItem()->isOptional()) { 
                                $item = new InvoiceItem();
                                $item->setInvoice($invoice);
                                $item->setFeeItem($fee->getFeeItem());
                                $item->setDescription($fee->getFeeItem()->getName());
                                $item->setAmount((string)$fee->getAmount());
                                $em->persist($item);
                                $total += $fee->getAmount();
                            }
                        }

                        if ($total > 0) {
                            $discountValue = $discountCalculator->calculateDiscount($student, $total);
                            $invoice->setTotalAmount((string)$total);
                            $invoice->setDiscountApplied((string)$discountValue);
                            $em->persist($invoice);
                        }
                    }
                }

                return true; 
            });

            // Trigger SMS if database transaction was successful
            if ($admissionSuccess) {
                // 🟢 FIXED: Use TenantSchool to prevent NotificationService crash
                $tenantSchool = $em->getRepository(TenantSchool::class)->findOneBy([]);

                if ($tenantSchool) {
                    $notifier->sendSms(
                        $tenantSchool, 
                        $guardian->getPhoneNumber(), 
                        "Admission Successful! [student_name] has been admitted to [class] at [school_name]. Adm No: [adm_no]", 
                        'enrollment', 
                        [
                            '[student_name]' => $student->getFullName(),
                            '[class]'        => $student->getCurrentClassroom()?->getName() ?? 'New Intake',
                            '[school_name]'  => $tenantSchool->getName(),
                            '[adm_no]'       => $student->getAdmissionNumber()
                        ]
                    );
                }
            }

            $this->addFlash('success', "Admission Successful! Student enrolled and billed automatically.");
            
            return $this->render('tenant/admission/success.html.twig', [
                'guardian' => $guardian,
                'student' => $student
            ]);
        }

        return $this->render('tenant/admission/register.html.twig', [
            'form' => $form->createView(),
            'guardian' => $guardian
        ]);
    }

    #[Route('/api/states/{id}', name: 'app_tenant_api_states', methods: ['GET'])]
    public function getStatesByCountry(\App\Entity\Tenant\Country $country): JsonResponse
    {
        $states = [];
        foreach ($country->getStates() as $state) {
            $states[] = ['id' => $state->getId(), 'name' => $state->getName()];
        }
        return new JsonResponse($states);
    }

    #[Route('/api/lgas/{id}', name: 'app_tenant_api_lgas', methods: ['GET'])]
    public function getLgasByState(State $state): JsonResponse
    {
        $lgas = [];
        foreach ($state->getLgas() as $lga) {
            $lgas[] = ['id' => $lga->getId(), 'name' => $lga->getName()];
        }
        return new JsonResponse($lgas);
    }

    #[Route('/student/{id}/print-letter', name: 'app_tenant_admission_print', methods: ['GET'])]
    public function printLetter(Student $student): Response
    {
        /** @var \App\Entity\Tenant\User $user */
        $user = $this->getUser();
        $tenant = $user->getTenant();

        return $this->render('tenant/admission/print_letter.html.twig', [
            'student' => $student,
            'tenant' => $tenant
        ]);
    }
}