<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\ResultComponentScore;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Subject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\YearGroupMaster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/tenant/approvals')]
#[IsGranted('ROLE_TEACHER')]
class ApprovalController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'tenant_approval_hub')]
    public function hub(): Response
    {
        $staff = $this->getStaff();

        return $this->render('tenant/approval/hub.html.twig', [
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
            'formTeacherGroups' => $this->formTeacherGroups($staff),
            'hodGroups' => $this->hodGroups($staff),
            'yearGroupGroups' => $this->yearGroupGroups($staff),
        ]);
    }

    #[Route('/form-teacher/{id}/confirm', name: 'tenant_approval_form_teacher_confirm', methods: ['POST'])]
    public function confirmClass(FormTeacher $formTeacher): Response
    {
        $staff = $this->getStaff();

        if ($formTeacher->getStaff()->getId() !== $staff->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $term = $formTeacher->getTerm();
        foreach ($this->classSubjectsForClassroom($formTeacher->getClassroom(), $term->getSession()) as $classSubject) {
            $approval = $this->approvalFor($classSubject, $term);
            $approval->setFormTeacherStatus(ResultApproval::STATUS_APPROVED);
            $approval->setFormTeacher($staff);
            $approval->setFormTeacherAt(new \DateTimeImmutable());
            $this->em->persist($approval);
        }

        $this->em->flush();
        $this->addFlash('success', 'Class results confirmed.');

        return $this->redirectToRoute('tenant_approval_hub');
    }

    #[Route('/hod/{id}/approve', name: 'tenant_approval_hod_approve', methods: ['POST'])]
    public function hodApprove(ResultApproval $approval): Response
    {
        $staff = $this->getStaff();
        $this->assertHodOf($approval, $staff);

        $approval->setHodStatus(ResultApproval::STATUS_APPROVED);
        $approval->setHod($staff);
        $approval->setHodAt(new \DateTimeImmutable());
        $this->em->persist($approval);
        $this->em->flush();
        $this->addFlash('success', 'Subject results approved.');

        return $this->redirectToRoute('tenant_approval_hub');
    }

    #[Route('/hod/{id}/reject', name: 'tenant_approval_hod_reject', methods: ['POST'])]
    public function hodReject(ResultApproval $approval): Response
    {
        $staff = $this->getStaff();
        $this->assertHodOf($approval, $staff);

        $approval->setHodStatus(ResultApproval::STATUS_REJECTED);
        $approval->setHod($staff);
        $approval->setHodAt(new \DateTimeImmutable());

        // Auto-unlock: send the result back to the teacher for rework.
        $approval->setFormTeacherStatus(ResultApproval::STATUS_PENDING);
        $approval->setFormTeacher(null);
        $approval->setFormTeacherAt(null);
        $approval->setYearGroupStatus(ResultApproval::STATUS_PENDING);
        $approval->setYearGroupMaster(null);
        $approval->setYearGroupAt(null);

        $this->resetScoresToDraft($approval);

        $this->em->persist($approval);
        $this->em->flush();
        $this->addFlash('warning', 'Subject results rejected and unlocked for rework.');

        return $this->redirectToRoute('tenant_approval_hub');
    }

    #[Route('/year-group/{id}/approve', name: 'tenant_approval_year_group_approve', methods: ['POST'])]
    public function yearGroupApprove(ResultApproval $approval): Response
    {
        $staff = $this->getStaff();
        $this->assertYearGroupMasterOf($approval, $staff);

        $approval->setYearGroupStatus(ResultApproval::STATUS_APPROVED);
        $approval->setYearGroupMaster($staff);
        $approval->setYearGroupAt(new \DateTimeImmutable());
        $this->em->persist($approval);
        $this->em->flush();
        $this->addFlash('success', 'Results final-approved.');

        return $this->redirectToRoute('tenant_approval_hub');
    }

    #[Route('/{id}/unlock', name: 'tenant_approval_unlock', methods: ['POST'])]
    public function unlock(ResultApproval $approval): Response
    {
        $staff = $this->getStaff();

        if (!$this->isGranted('ROLE_ADMIN')) {
            $this->assertHodOf($approval, $staff);
        }

        $approval->setFormTeacherStatus(ResultApproval::STATUS_PENDING);
        $approval->setFormTeacher(null);
        $approval->setFormTeacherAt(null);
        $approval->setHodStatus(ResultApproval::STATUS_PENDING);
        $approval->setHod(null);
        $approval->setHodAt(null);
        $approval->setYearGroupStatus(ResultApproval::STATUS_PENDING);
        $approval->setYearGroupMaster(null);
        $approval->setYearGroupAt(null);

        // Reopen the scores for the teacher to edit.
        $this->resetScoresToDraft($approval);

        $this->em->persist($approval);
        $this->em->flush();
        $this->addFlash('success', 'Result unlocked for editing.');

        return $this->redirectToRoute('tenant_approval_hub');
    }

    private function resetScoresToDraft(ResultApproval $approval): void
    {
        $scores = $this->em->getRepository(ResultComponentScore::class)->findBy([
            'classSubject' => $approval->getClassSubject(),
            'term' => $approval->getTerm(),
        ]);
        foreach ($scores as $score) {
            $score->setStatus(ResultComponentScore::STATUS_DRAFT);
        }
    }

    // ------------------------------------------------------------
    // Dashboard data builders
    // ------------------------------------------------------------

    private function formTeacherGroups(Staff $staff): array
    {
        $groups = [];
        $formTeachers = $this->em->getRepository(FormTeacher::class)->findBy(['staff' => $staff], ['term' => 'ASC']);

        foreach ($formTeachers as $formTeacher) {
            $term = $formTeacher->getTerm();
            $groups[] = [
                'assignment' => $formTeacher,
                'items' => $this->buildItems(
                    $this->classSubjectsForClassroom($formTeacher->getClassroom(), $term->getSession()),
                    $term
                ),
            ];
        }

        return $groups;
    }

    private function hodGroups(Staff $staff): array
    {
        $groups = [];
        $hodAssignments = $this->em->getRepository(DepartmentHead::class)->findBy(['staff' => $staff]);

        foreach ($hodAssignments as $hod) {
            $term = $this->getActiveTerm($hod->getSession());
            $subjects = $this->em->getRepository(Subject::class)->findBy(['department' => $hod->getDepartment()]);

            $bySubject = [];
            if ($subjects) {
                $classSubjects = $this->em->getRepository(ClassSubject::class)->findBy([
                    'subject' => $subjects,
                    'session' => $hod->getSession(),
                ]);

                foreach ($classSubjects as $classSubject) {
                    $bySubject[$classSubject->getSubject()->getId()]['subject'] = $classSubject->getSubject();
                    $bySubject[$classSubject->getSubject()->getId()]['items'][] = $this->itemFor($classSubject, $term);
                }
            }

            $groups[] = [
                'assignment' => $hod,
                'term' => $term,
                'subjects' => array_values($bySubject),
            ];
        }

        return $groups;
    }

    private function yearGroupGroups(Staff $staff): array
    {
        $groups = [];
        $ygmAssignments = $this->em->getRepository(YearGroupMaster::class)->findBy(['staff' => $staff]);

        foreach ($ygmAssignments as $ygm) {
            $term = $this->getActiveTerm($ygm->getSession());
            $classrooms = $this->em->getRepository(Classroom::class)->findBy(['yearGroup' => $ygm->getYearGroup()]);

            $items = [];
            if ($classrooms) {
                $classSubjects = $this->em->getRepository(ClassSubject::class)->findBy([
                    'classroom' => $classrooms,
                    'session' => $ygm->getSession(),
                ]);
                $items = $this->buildItems($classSubjects, $term);
            }

            $groups[] = [
                'assignment' => $ygm,
                'term' => $term,
                'items' => $items,
            ];
        }

        return $groups;
    }

    private function buildItems(array $classSubjects, ?Term $term): array
    {
        $items = [];
        foreach ($classSubjects as $classSubject) {
            $items[] = $this->itemFor($classSubject, $term);
        }

        return $items;
    }

    private function itemFor(ClassSubject $classSubject, ?Term $term): array
    {
        $approval = $term ? $this->approvalFor($classSubject, $term) : null;

        $teacher = null;
        if ($term) {
            $subjectTeacher = $this->em->getRepository(SubjectTeacher::class)->findOneBy([
                'subject' => $classSubject->getSubject(),
                'classroom' => $classSubject->getClassroom(),
                'term' => $term,
            ]);
            $teacher = $subjectTeacher?->getStaff();
        }

        return [
            'classSubject' => $classSubject,
            'approval' => $approval,
            'teacher' => $teacher,
        ];
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function getStaff(): Staff
    {
        /** @var \App\Entity\Tenant\User $user */
        $user = $this->getUser();
        $staff = $user->getStaff();

        if (!$staff) {
            throw $this->createAccessDeniedException('No staff profile linked to your account.');
        }

        return $staff;
    }

    private function classSubjectsForClassroom(Classroom $classroom, ?Session $session): array
    {
        $criteria = ['classroom' => $classroom];
        if ($session) {
            $criteria['session'] = $session;
        }

        return $this->em->getRepository(ClassSubject::class)->findBy($criteria, ['subject' => 'ASC']);
    }

    private function approvalFor(ClassSubject $classSubject, Term $term): ResultApproval
    {
        $approval = $this->em->getRepository(ResultApproval::class)->findOneBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);

        if (!$approval) {
            $approval = new ResultApproval();
            $approval->setClassSubject($classSubject);
            $approval->setTerm($term);
        }

        return $approval;
    }

    private function getActiveTerm(Session $session): ?Term
    {
        $term = $this->em->getRepository(Term::class)->findOneBy(
            ['session' => $session, 'isActive' => true],
            ['id' => 'ASC']
        );

        if (!$term) {
            $term = $this->em->getRepository(Term::class)->findOneBy(
                ['session' => $session],
                ['id' => 'DESC']
            );
        }

        return $term;
    }

    private function assertHodOf(ResultApproval $approval, Staff $staff): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        $department = $approval->getClassSubject()->getSubject()->getDepartment();
        if (!$department) {
            throw $this->createAccessDeniedException();
        }

        $hod = $this->em->getRepository(DepartmentHead::class)->findOneBy([
            'staff' => $staff,
            'department' => $department,
            'session' => $approval->getTerm()->getSession(),
        ]);

        if (!$hod) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertYearGroupMasterOf(ResultApproval $approval, Staff $staff): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        $yearGroup = $approval->getClassSubject()->getClassroom()->getYearGroup();
        if (!$yearGroup) {
            throw $this->createAccessDeniedException();
        }

        $ygm = $this->em->getRepository(YearGroupMaster::class)->findOneBy([
            'staff' => $staff,
            'yearGroup' => $yearGroup,
            'session' => $approval->getTerm()->getSession(),
        ]);

        if (!$ygm) {
            throw $this->createAccessDeniedException();
        }
    }
}
