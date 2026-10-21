<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\State;
use App\Form\GuardianType;
use App\Form\StudentAdmissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\AdmissionService; // 🟢 shared admission engine (walk-in + online)
use Symfony\Component\HttpFoundation\JsonResponse;use Symfony\Component\ExpressionLanguage\Expression;use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admission')]
// ADMISSION IS NOT A FINANCE DECISION — the principal/administrator or the
// admissions officer (ROLE_ADMISSION_OFFICER), never the bursar.
#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_ADMISSION_OFFICER')"))]
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
        AdmissionService $admissions // 🟢 shared admission engine
    ): Response {
        $student = new Student();
        $student->setGuardian($guardian); 
        $names = explode(' ', $guardian->getFullName());
        $student->setLastName($names[count($names)-1] ?? '');

        $form = $this->createForm(StudentAdmissionType::class, $student);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 🟢 One shared admission engine: admission number → Enrollment →
            //    invoice + discount → SMS. The online application flow calls the
            //    exact same service, so walk-in and online students can never
            //    be treated differently.
            $admissions->admit($student);

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
}