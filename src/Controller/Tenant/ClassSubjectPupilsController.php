<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Student;
use App\Service\Subjects\PupilSubjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Who takes this subject in this arm.
 *
 * The class says the arm offers IRS; this screen says the pupil who takes CRK
 * does not. One row per pupil, ticked by default, unticked by the form teacher —
 * no groups, no "choose one of", just the tick the school asked for.
 *
 * The same screen also feeds the older elective mechanism: an elective class
 * subject is opt-in, so ticking a pupil there is what puts them on the sheet.
 */
#[Route('/class-subjects/{id}/pupils', name: 'app_tenant_class_subject_pupils', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_ADMIN')]
final class ClassSubjectPupilsController extends AbstractController
{
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function pupils(
        ClassSubject $classSubject,
        Request $request,
        PupilSubjects $pupils,
        EntityManagerInterface $em,
    ): Response {
        $classroom = $classSubject->getClassroom();
        if ($classroom === null) {
            throw $this->createNotFoundException('That class subject has no class.');
        }

        /** @var list<Student> $roster */
        $roster = $pupils->roster($classroom);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('subject_pupils_' . $classSubject->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Please try again.');

                return $this->redirectToRoute('app_tenant_class_subject_pupils', ['id' => $classSubject->getId()]);
            }

            $ticked = [];
            foreach ((array) $request->request->all()['takes'] as $id) {
                $ticked[(int) $id] = true;
            }

            $staff = $this->getUser()?->getStaff();
            $added = 0;
            $removed = 0;

            foreach ($roster as $student) {
                $wants = isset($ticked[(int) $student->getId()]);
                $has = $pupils->takes($student, $classSubject);

                if ($wants === $has) {
                    continue;
                }

                if ($wants) {
                    if ($classSubject->isElective()) {
                        $pupils->optIn($student, $classSubject);
                    }
                    $pupils->include($student, $classSubject);
                    ++$added;
                } else {
                    if ($classSubject->isElective()) {
                        $pupils->optOut($student, $classSubject);
                    } else {
                        $pupils->exempt($student, $classSubject, $staff);
                    }
                    ++$removed;
                }
            }

            $em->flush();

            $this->addFlash($added + $removed > 0 ? 'success' : 'info', match (true) {
                $added > 0 && $removed > 0 => sprintf('%d pupil%s added and %d removed from %s.', $added, $added === 1 ? '' : 's', $removed, $classSubject->getSubject()?->getName()),
                $added > 0 => sprintf('%d pupil%s added to %s.', $added, $added === 1 ? '' : 's', $classSubject->getSubject()?->getName()),
                $removed > 0 => sprintf('%d pupil%s no longer take%s %s.', $removed, $removed === 1 ? '' : 's', $removed === 1 ? 's' : '', $classSubject->getSubject()?->getName()),
                default => 'Nothing changed.',
            });

            return $this->redirectToRoute('app_tenant_class_subject_pupils', ['id' => $classSubject->getId()]);
        }

        $state = [];
        foreach ($roster as $student) {
            $state[(int) $student->getId()] = $pupils->takes($student, $classSubject);
        }

        return $this->render('tenant/class_subject/pupils.html.twig', [
            'classSubject' => $classSubject,
            'classroom' => $classroom,
            'roster' => $roster,
            'state' => $state,
            'takers' => count(array_filter($state)),
        ]);
    }
}
