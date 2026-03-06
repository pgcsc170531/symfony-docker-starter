<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\State;
use App\Entity\Tenant\FeeStructure; // ✅ CHANGED: Use FeeStructure
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Form\GuardianType;
use App\Form\StudentAdmissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

        // 🛑 FIX: Check for duplicate BEFORE validation runs
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

        // Now safe to check validity for NEW parents
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

    // 2. REGISTER PAGE (Add Child + Auto-Bill)
  #[Route('/register/{id}', name: 'app_tenant_admission_register', methods: ['GET', 'POST'])]
        public function register(
            Guardian $guardian, 
            Request $request, 
            EntityManagerInterface $em,
            NotificationService $notifier, // 👈 Injected
            ManagerRegistry $doctrine      // 👈 Injected for Landlord access
        ): Response {
            $student = new Student();
            $student->setGuardian($guardian); 
            $names = explode(' ', $guardian->getFullName());
            $student->setLastName($names[count($names)-1] ?? '');

            $form = $this->createForm(StudentAdmissionType::class, $student);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                
                // 🟢 FIX 1: wrapInTransaction returns the value returned inside the closure
                $admissionSuccess = $em->wrapInTransaction(function($em) use ($student, $form) {
                    
                    // ... (All your existing logic for Admission No, Enrollment, and Auto-Billing) ...

                    $em->persist($student);
                    
                    // We return true so that $admissionSuccess becomes true
                    return true; 
                });

                // 🟢 FIX 2: Trigger SMS only if database transaction was successful
                if ($admissionSuccess) {
                    /** @var \App\Entity\Tenant\User $user */
                    $user = $this->getUser();
                    $tenant = $user->getTenant();

                    // Fetch the Landlord School entity to handle the wallet deduction
                    $landlordEm = $doctrine->getManager('landlord');
                    $landlordSchool = $landlordEm->getRepository(\App\Entity\Landlord\School::class)->findOneBy([
                        'subdomain' => $tenant->getSubdomain()
                    ]);

                    if ($landlordSchool) {
                        $notifier->sendSms(
                            $landlordSchool, 
                            $guardian->getPhoneNumber(), 
                            "Admission Successful! [student_name] has been admitted to [class] at [school_name]. Adm No: [adm_no]", 
                            'enrollment', 
                            [
                                '[student_name]' => $student->getFullName(),
                                '[class]'        => $student->getCurrentClassroom()?->getName() ?? 'New Intake',
                                '[school_name]'  => $tenant->getName(),
                                '[adm_no]'       => $student->getAdmissionNumber()
                            ]
                        );
                    }
                }

                $this->addFlash('success', "Admission Successful!");
                
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
    

    

    #[Route('/api/lgas/{id}', name: 'app_tenant_api_lgas', methods: ['GET'])]
    public function getLgasByState(State $state): JsonResponse
    {
        $lgas = [];
        foreach ($state->getLgas() as $lga) {
            $lgas[] = [
                'id' => $lga->getId(),
                'name' => $lga->getName()
            ];
        }
        return new JsonResponse($lgas);
    }
}