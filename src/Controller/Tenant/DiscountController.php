<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\DiscountType;
use App\Entity\Tenant\StudentDiscount;
use App\Entity\Tenant\Student;
use App\Form\DiscountTypeForm;
use App\Form\StudentDiscountAssignmentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/discounts')]
class DiscountController extends AbstractController
{
    // 1. Manage Rules (e.g. Create "Staff Child")
    #[Route('/types', name: 'app_tenant_discount_types', methods: ['GET', 'POST'])]
    public function types(Request $request, EntityManagerInterface $em): Response
    {
        $discountType = new DiscountType();
        $form = $this->createForm(DiscountTypeForm::class, $discountType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($discountType);
            $em->flush();
            $this->addFlash('success', 'New discount rule created!');
            return $this->redirectToRoute('app_tenant_discount_types');
        }

        $types = $em->getRepository(DiscountType::class)->findAll();

        return $this->render('tenant/discount/types.html.twig', [
            'form' => $form,
            'types' => $types
        ]);
    }

   // 2. Manage Students (Search -> Select -> Assign)
    #[Route('/assign', name: 'app_tenant_discount_assign', methods: ['GET', 'POST'])]
    public function assign(Request $request, EntityManagerInterface $em): Response
    {
        // A. Handle Student Search
        $searchQuery = $request->query->get('q');
        $searchResults = [];
        if ($searchQuery) {
            $searchResults = $em->getRepository(Student::class)
                ->createQueryBuilder('s')
                ->where('s.firstName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q')
                ->setParameter('q', '%' . $searchQuery . '%')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        }

        // B. Handle Selection (If user clicked "Select" on a student)
        $selectedStudentId = $request->query->get('student_id');
        $selectedStudent = null;
        $form = null;

        if ($selectedStudentId) {
            $selectedStudent = $em->getRepository(Student::class)->find($selectedStudentId);
            
            if ($selectedStudent) {
                // Create the form ONLY if a student is selected
                $assignment = new StudentDiscount();
                $assignment->setStudent($selectedStudent); // Pre-fill the student

                $form = $this->createForm(StudentDiscountAssignmentType::class, $assignment);
                $form->handleRequest($request);

                if ($form->isSubmitted() && $form->isValid()) {
                    // Check for duplicate
                    $exists = $em->getRepository(StudentDiscount::class)->findOneBy([
                        'student' => $assignment->getStudent()
                    ]);

                    if ($exists) {
                        $this->addFlash('error', 'This student already has a discount. Remove it first.');
                    } else {
                        $em->persist($assignment);
                        $em->flush();
                        $this->addFlash('success', "Scholarship assigned to {$selectedStudent->getFirstName()}!");
                    }
                    // Clear selection after saving
                    return $this->redirectToRoute('app_tenant_discount_assign');
                }
            }
        }

        // C. List Active Scholarships (Always visible)
        $activeDiscounts = $em->getRepository(StudentDiscount::class)->findAll();

        return $this->render('tenant/discount/assign.html.twig', [
            'form' => $form ? $form->createView() : null, // Only pass form if student selected
            'searchResults' => $searchResults,
            'searchQuery' => $searchQuery,
            'selectedStudent' => $selectedStudent,
            'activeDiscounts' => $activeDiscounts
        ]);
    }

    // 3. Remove a Discount
    #[Route('/remove/{id}', name: 'app_tenant_discount_remove', methods: ['POST'])]
    public function remove(StudentDiscount $discount, EntityManagerInterface $em): Response
    {
        $em->remove($discount);
        $em->flush();
        $this->addFlash('success', 'Discount removed from student.');
        return $this->redirectToRoute('app_tenant_discount_assign');
    }
}