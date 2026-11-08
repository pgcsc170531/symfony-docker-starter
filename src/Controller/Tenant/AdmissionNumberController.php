<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Student;
use App\Form\AdmissionNumberType;
use App\Service\Students\StudentRemover;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Correct or remove a pupil's admission number.
 *
 * Why this exists: an admission number is issued by the system at admission and
 * is never part of the profile edit form — which is right, because it is on the
 * invoices, receipts, ID card and admission letter already issued. But a school
 * uploading its existing roll brings numbers with it, and a mis-typed row has to
 * be fixable without deleting the whole import.
 *
 * SUPER ADMIN ONLY. Renumbering rewrites an identifier the school has already
 * printed, and deleting a pupil removes their enrolment and bills with it.
 *
 * A duplicate number is refused with the name and class of the pupil who already
 * holds it: "ADM/2026/0007 is already the admission number of Fatima Abubakar
 * in JSS 1 A." The column is UNIQUE, so the message has to do the explaining —
 * a raw SQLSTATE 23000 tells a bursar nothing.
 */
#[Route('/settings/admission-numbers')]
#[IsGranted('ROLE_TENANT_SUPER_ADMIN')]
final class AdmissionNumberController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(private readonly StudentRemover $remover)
    {
    }

    #[Route('', name: 'app_tenant_admission_numbers', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $classId = (int) $request->query->get('class_id', 0);
        $page = max(1, (int) $request->query->get('page', 1));

        $qb = $em->getRepository(Student::class)->createQueryBuilder('s')
            ->orderBy('s.admissionNumber', 'ASC')
            ->addOrderBy('s.lastName', 'ASC')
            ->addOrderBy('s.firstName', 'ASC')
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE);

        if ($q !== '') {
            $qb->andWhere('s.admissionNumber LIKE :q
                        OR s.firstName LIKE :q
                        OR s.middleName LIKE :q
                        OR s.lastName LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        if ($classId > 0) {
            $qb->andWhere('s.currentClassroom = :classroom OR s.currentClass = :classroom')
                ->setParameter('classroom', $classId);
        }

        $students = new Paginator($qb);
        $total = count($students);

        return $this->render('tenant/settings/admission_numbers/index.html.twig', [
            'students' => $students,
            'classrooms' => $em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'q' => $q,
            'class_id' => $classId,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_admission_number_edit', methods: ['GET', 'POST'])]
    public function edit(Student $student, Request $request, EntityManagerInterface $em): Response
    {
        $current = (string) $student->getAdmissionNumber();

        $form = $this->createForm(AdmissionNumberType::class, ['admissionNumber' => $current]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $number = self::normalise((string) $form->get('admissionNumber')->getData());
            $clash = $this->pupilAlreadyHolding($em, $number, $student);

            if ($clash !== null) {
                $form->get('admissionNumber')->addError(new FormError(self::clashMessage($number, $clash)));
            } else {
                $before = $current !== '' ? $current : 'none';
                $student->setAdmissionNumber($number);

                try {
                    $em->flush();

                    $this->addFlash('success', sprintf(
                        'Admission number changed from %s to %s for %s.',
                        $before,
                        $number,
                        $student->getFullName()
                    ));

                    return $this->redirectToRoute('app_tenant_admission_numbers');
                } catch (UniqueConstraintViolationException) {
                    // Another request took the number between our check and the
                    // write. Re-read it so the message still names the holder.
                    $student->setAdmissionNumber($current !== '' ? $current : null);
                    $clash = $this->pupilAlreadyHolding($em, $number, $student);

                    $form->get('admissionNumber')->addError(new FormError(
                        $clash !== null
                            ? self::clashMessage($number, $clash)
                            : 'That number was just taken by another pupil. Try a different one.'
                    ));
                }
            }
        }

        $guardian = $student->getGuardian();

        return $this->render('tenant/settings/admission_numbers/edit.html.twig', [
            'form' => $form->createView(),
            'student' => $student,
            'class_name' => self::classNameOf($student),
            'guardian_name' => $guardian?->getFullName(),
            // The guardian is never deleted with the pupil: a family can have more
            // than one child here, and their login outlives any single enrolment.
            'other_children' => $guardian !== null ? max(0, $guardian->getStudents()->count() - 1) : 0,
            'delete_blocker' => $this->remover->blocker($student),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_tenant_admission_number_delete', methods: ['POST'])]
    public function delete(Student $student, Request $request): Response
    {
        $id = (int) $student->getId();
        $number = (string) $student->getAdmissionNumber();
        $name = $student->getFullName();

        if (!$this->isCsrfTokenValid('delete_student_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        // Type the number to confirm — same guard as deleting a school. A row in a
        // table is one tap away from a pupil's whole financial history. A pupil with
        // no number yet is confirmed with the word DELETE, so the guard never
        // silently disappears just because the column is empty.
        $expected = $number !== '' ? $number : 'DELETE';

        if (strcasecmp(trim((string) $request->request->get('confirm')), $expected) !== 0) {
            $this->addFlash('error', sprintf(
                'Nothing was deleted. Type %s exactly to confirm.',
                $number !== '' ? sprintf('the admission number (%s)', $number) : 'DELETE'
            ));

            return $this->redirectToRoute('app_tenant_admission_number_edit', ['id' => $id]);
        }

        $blocker = $this->remover->blocker($student);
        if ($blocker !== null) {
            $this->addFlash('error', 'Nothing was deleted. ' . $blocker);

            return $this->redirectToRoute('app_tenant_admission_number_edit', ['id' => $id]);
        }

        try {
            $this->remover->remove($student);
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf(
                'Could not delete %s: %s. Nothing was removed.',
                $name,
                $e->getMessage()
            ));

            return $this->redirectToRoute('app_tenant_admission_number_edit', ['id' => $id]);
        }

        $this->addFlash('success', sprintf(
            '%s (%s) was deleted, with their enrolment and unpaid bills.',
            $name,
            $number !== '' ? $number : 'no admission number'
        ));

        return $this->redirectToRoute('app_tenant_admission_numbers');
    }

    /** Trim and collapse runs of whitespace; the case is the school's own. */
    private static function normalise(string $number): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $number));
    }

    private function pupilAlreadyHolding(EntityManagerInterface $em, string $number, Student $student): ?Student
    {
        $qb = $em->getRepository(Student::class)->createQueryBuilder('s')
            ->andWhere('s.admissionNumber = :number')
            ->setParameter('number', $number)
            ->setMaxResults(1);

        if ($student->getId() !== null) {
            $qb->andWhere('s.id != :id')->setParameter('id', $student->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    private static function clashMessage(string $number, Student $holder): string
    {
        return sprintf(
            '%s is already the admission number of %s in %s. Two pupils cannot share a number — give this one a different number, or move that number off %s first.',
            $number,
            $holder->getFullName(),
            self::classNameOf($holder),
            $holder->getFirstName() ?: 'the other pupil'
        );
    }

    private static function classNameOf(Student $student): string
    {
        return $student->getCurrentClassroom()?->getName()
            ?: $student->getCurrentClass()?->getName()
            ?: 'no class yet';
    }
}
