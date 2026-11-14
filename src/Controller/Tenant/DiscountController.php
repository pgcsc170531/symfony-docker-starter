<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\DiscountType;
use App\Entity\Tenant\StudentDiscount;
use App\Entity\Tenant\Student;
use App\Form\DiscountTypeForm;
use App\Form\StudentDiscountAssignmentType;
use App\Service\Finance\DiscountRepricer;
use App\Service\Wording\LearnerWording;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/finance/discounts')]
#[IsGranted('ROLE_BURSAR')]
class DiscountController extends AbstractController
{
    public function __construct(
        private readonly DiscountRepricer $repricer,
        private readonly LearnerWording $wording,
    ) {
    }
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

        return $this->renderRuleBoard($em, $form);
    }

    // 1b. Edit an existing rule, then re-price the bills that use it.
    #[Route('/types/{id}/edit', name: 'app_tenant_discount_type_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(DiscountType $discountType, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DiscountTypeForm::class, $discountType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            // The rule is the source of truth, so every open bill using it must
            // move with it. This is what makes a change from 50% to 100% reach
            // the families who already have a bill.
            $repriced = $this->repricer->repriceRule($discountType);

            $message = sprintf('Discount rule "%s" updated.', $discountType->getName() ?: 'Rule');
            $message .= $repriced['invoices'] > 0
                ? sprintf(
                    ' %d open bill%s re-priced across %d pupil%s.',
                    $repriced['invoices'],
                    $repriced['invoices'] === 1 ? '' : 's',
                    $repriced['students'],
                    $repriced['students'] === 1 ? '' : 's',
                )
                : ' No open bills needed changing.';

            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_tenant_discount_types');
        }

        return $this->renderRuleBoard($em, $form, $discountType);
    }

    // 1c. Delete an unused rule. An assigned rule must be revoked from its
    // pupils first, because deleting it would silently re-open their bills.
    #[Route('/types/{id}/delete', name: 'app_tenant_discount_type_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(DiscountType $discountType, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_discount_type_' . $discountType->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $assigned = $em->getRepository(StudentDiscount::class)->count(['discountType' => $discountType]);

        if ($assigned > 0) {
            $this->addFlash('error', sprintf(
                'Rule "%s" is assigned to %d pupil%s. Revoke it from those pupils before deleting it.',
                $discountType->getName() ?: 'This rule',
                $assigned,
                $assigned === 1 ? '' : 's',
            ));

            return $this->redirectToRoute('app_tenant_discount_types');
        }

        $name = $discountType->getName() ?: 'Discount rule';
        $em->remove($discountType);
        $em->flush();
        $this->addFlash('success', sprintf('Discount rule "%s" deleted.', $name));

        return $this->redirectToRoute('app_tenant_discount_types');
    }

    private function renderRuleBoard(EntityManagerInterface $em, FormInterface $form, ?DiscountType $editing = null): Response
    {
        $types = $em->getRepository(DiscountType::class)->findBy([], ['name' => 'ASC']);

        // One query for the "in use" column, rather than a count per rule.
        $assignmentCounts = [];
        $rows = $em->getRepository(StudentDiscount::class)
            ->createQueryBuilder('sd')
            ->select('IDENTITY(sd.discountType) AS ruleId, COUNT(sd.id) AS total')
            ->groupBy('sd.discountType')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $assignmentCounts[(int) $row['ruleId']] = (int) $row['total'];
        }

        return $this->render('tenant/discount/types.html.twig', [
            'form' => $form->createView(),
            'types' => $types,
            'assignmentCounts' => $assignmentCounts,
            'editing' => $editing,
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
                ->where('s.firstName LIKE :q OR s.middleName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q')
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
                        // Unticked means "follow the rule", which is stored as
                        // NULL so a later change to the rule still reaches this
                        // pupil. Ticked is a deliberate per-pupil widening.
                        $assignment->setAppliesToAll(
                            $form->get('appliesToAll')->getData() ? true : null
                        );

                        $em->persist($assignment);
                        $em->flush();
                        $this->addFlash('success', "Discount assigned to {$selectedStudent->getFirstName()}.");
                        $this->flashReprice($this->repricer->repriceOpenInvoices($selectedStudent), 'assign');
                    }
                    // Clear selection after saving
                    return $this->redirectToRoute('app_tenant_discount_assign');
                }
            }
        }

        // C. List Active Scholarships (Always visible)
        $activeDiscounts = $em->getRepository(StudentDiscount::class)->findAll();

        // Every row carries the pupil's actual bill, because a rule that never
        // reached the invoice looks identical to one that did.
        $effects = [];
        foreach ($activeDiscounts as $activeDiscount) {
            $discountedStudent = $activeDiscount->getStudent();
            $effects[$activeDiscount->getId()] = $discountedStudent
                ? $this->repricer->summary($discountedStudent)
                : null;
        }

        return $this->render('tenant/discount/assign.html.twig', [
            'form' => $form ? $form->createView() : null, // Only pass form if student selected
            'searchResults' => $searchResults,
            'searchQuery' => $searchQuery,
            'selectedStudent' => $selectedStudent,
            'activeDiscounts' => $activeDiscounts,
            'effects' => $effects,
        ]);
    }

    // 3. Remove a Discount
    #[Route('/remove/{id}', name: 'app_tenant_discount_remove', methods: ['POST'])]
    public function remove(StudentDiscount $discount, EntityManagerInterface $em): Response
    {
        $student = $discount->getStudent();

        $em->remove($discount);
        $em->flush();
        $this->addFlash('success', 'Discount removed from student.');

        // The rule is gone, so anything still open goes back to the gross bill.
        if ($student) {
            $this->flashReprice($this->repricer->repriceOpenInvoices($student), 'remove');
        }

        return $this->redirectToRoute('app_tenant_discount_assign');
    }

    /**
     * Tell the bursar what the assignment actually did to the money.
     *
     * Silence here was the bug: the rule was saved, the bill did not move, and
     * nothing on screen said why. Saying "no open bill was changed" is just as
     * important as reporting the new total.
     *
     * @param list<array{number: string, from: float, to: float, discount: float}> $changes
     */
    private function flashReprice(array $changes, string $action): void
    {
        if ($changes === []) {
            $this->addFlash('info', $action === 'remove'
                ? 'No open bill needed changing — the ' . $this->wording->singular() . ' was already billed the full amount.'
                : 'No open bill was changed — the rule will apply to bills generated from now on.');
            return;
        }

        foreach ($changes as $change) {
            $this->addFlash('success', $action === 'remove'
                ? sprintf(
                    'Bill %s put back to ₦%s (it was ₦%s with the scholarship).',
                    $change['number'],
                    number_format($change['to'], 2),
                    number_format($change['from'], 2),
                )
                : sprintf(
                    'Bill %s re-priced: ₦%s → ₦%s (₦%s discount).',
                    $change['number'],
                    number_format($change['from'], 2),
                    number_format($change['to'], 2),
                    number_format($change['discount'], 2),
                ));
        }
    }
}