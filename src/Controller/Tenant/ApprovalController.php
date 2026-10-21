<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\ResultComponentScore;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Subject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\YearGroupMaster;
use App\Service\SchoolMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        // An administrator or overseer may hold no assignment of their own. They
        // still need the hub to open — it is the screen they reach from the
        // dashboard's approval counts — so it renders with empty groups rather
        // than a 403. A teacher without a Staff profile is a real data error and
        // keeps the explicit refusal.
        $user = $this->getUser();
        $staff = $user instanceof \App\Entity\Tenant\User ? $user->getStaff() : null;

        if (!$staff && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No staff profile linked to your account.');
        }

        return $this->render('tenant/approval/hub.html.twig', [
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
            'formTeacherGroups' => $staff ? $this->formTeacherGroups($staff) : [],
            'hodGroups' => $staff ? $this->hodGroups($staff) : [],
            'yearGroupGroups' => $staff ? $this->yearGroupGroups($staff) : [],
        ]);
    }

    #[Route('/form-teacher/{id}/confirm', name: 'tenant_approval_form_teacher_confirm', methods: ['POST'])]
    public function confirmClass(FormTeacher $formTeacher, Request $request): Response
    {
        $this->assertCsrf($request);
        $staff = $this->getStaff();

        if ($formTeacher->getStaff()->getId() !== $staff->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $term = $formTeacher->getTerm();
        $classSubjects = $this->classSubjectsForClassroom($formTeacher->getClassroom(), $term->getSession());

        $missing = [];
        foreach ($classSubjects as $classSubject) {
            $submitted = (int) $this->em->getRepository(ResultComponentScore::class)->count([
                'classSubject' => $classSubject,
                'term' => $term,
                'status' => ResultComponentScore::STATUS_SUBMITTED,
            ]);
            if ($submitted === 0) {
                $missing[] = $classSubject->getSubject()?->getName() ?? 'a subject';
            }
        }

        if ($missing !== []) {
            $this->addFlash('warning', 'Cannot confirm yet — no submitted results for: ' . implode(', ', $missing) . '.');

            return $this->redirectToRoute('tenant_approval_hub');
        }

        foreach ($classSubjects as $classSubject) {
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
    public function hodApprove(ResultApproval $approval, Request $request): Response
    {
        $this->assertCsrf($request);
        $staff = $this->getStaff();
        $this->assertHodOf($approval, $staff);

        if (!$this->isGranted('ROLE_ADMIN') && $approval->getFormTeacherStatus() !== ResultApproval::STATUS_APPROVED) {
            $this->addFlash('warning', 'The form teacher must confirm the class before the HOD can approve this subject.');

            return $this->redirectToRoute('tenant_approval_hub');
        }

        $approval->setHodStatus(ResultApproval::STATUS_APPROVED);
        $approval->setHod($staff);
        $approval->setHodAt(new \DateTimeImmutable());
        $this->em->persist($approval);
        $this->em->flush();
        $this->addFlash('success', 'Subject results approved.');

        return $this->redirectToRoute('tenant_approval_hub');
    }

    #[Route('/hod/{id}/reject', name: 'tenant_approval_hod_reject', methods: ['POST'])]
    public function hodReject(ResultApproval $approval, Request $request): Response
    {
        $this->assertCsrf($request);
        $staff = $this->getStaff();
        $this->assertHodOf($approval, $staff);

        // A rejection has to say what to fix — otherwise the teacher is simply
        // told "no" and resubmits the same sheet.
        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('error', 'Say why the results are being sent back — the subject teacher sees this reason.');
            return $this->redirectToRoute('tenant_approval_hub');
        }

        $approval->setHodStatus(ResultApproval::STATUS_REJECTED);
        $approval->setHodReason(mb_substr($reason, 0, 255));
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
    public function yearGroupApprove(YearGroupMaster $assignment, SchoolMailer $mailer, Request $request): Response
    {
        $this->assertCsrf($request);
        $staff = $this->getStaff();

        if (!$this->isGranted('ROLE_ADMIN') && $assignment->getStaff()?->getId() !== $staff->getId()) {
            throw $this->createAccessDeniedException('That year group is not yours to release.');
        }

        $term = $this->getActiveTerm($assignment->getSession());
        if (!$term) {
            $this->addFlash('error', 'No term is open for this year group.');
            return $this->redirectToRoute('tenant_approval_hub');
        }

        $classrooms = $this->em->getRepository(Classroom::class)->findBy(['yearGroup' => $assignment->getYearGroup()]);
        $classSubjects = $classrooms === [] ? [] : $this->em->getRepository(ClassSubject::class)->findBy([
            'classroom' => $classrooms,
            'session' => $assignment->getSession(),
        ]);

        if ($classSubjects === []) {
            $this->addFlash('warning', 'There are no class subjects in this year group to release.');
            return $this->redirectToRoute('tenant_approval_hub');
        }

        // Releasing is a year-group decision, not a per-subject one. A class that
        // is half-released shows parents some marks and hides the rest, so the
        // group goes out together or not at all — and the refusal names the
        // subjects that are holding it up.
        $outstanding = [];
        foreach ($classSubjects as $classSubject) {
            $approval = $this->em->getRepository(ResultApproval::class)->findOneBy([
                'classSubject' => $classSubject,
                'term' => $term,
            ]);

            if (!$approval || $approval->getHodStatus() !== ResultApproval::STATUS_APPROVED) {
                $outstanding[] = sprintf(
                    '%s (%s)',
                    $classSubject->getSubject()?->getName() ?? 'a subject',
                    $classSubject->getClassroom()?->getName() ?? 'a class'
                );
            }
        }

        if ($outstanding !== []) {
            $this->addFlash('warning', sprintf(
                'Cannot release yet — these subjects still need HOD approval: %s.',
                implode(', ', $outstanding)
            ));

            return $this->redirectToRoute('tenant_approval_hub');
        }

        // How many were already out before this click — used so a second press
        // of Release does not email every family again.
        $alreadyReleased = 0;
        foreach ($classSubjects as $classSubject) {
            $existing = $this->em->getRepository(ResultApproval::class)->findOneBy([
                'classSubject' => $classSubject,
                'term' => $term,
            ]);
            if ($existing && $existing->getYearGroupStatus() === ResultApproval::STATUS_APPROVED) {
                $alreadyReleased++;
            }
        }

        foreach ($classSubjects as $classSubject) {
            $approval = $this->approvalFor($classSubject, $term);
            $approval->setYearGroupStatus(ResultApproval::STATUS_APPROVED);
            $approval->setYearGroupMaster($staff);
            $approval->setYearGroupAt(new \DateTimeImmutable());
            $this->em->persist($approval);
        }

        $this->em->flush();
        $this->addFlash('success', sprintf(
            'Results for %s released — %d subjects final-approved.',
            $assignment->getYearGroup()?->getName() ?? 'the year group',
            count($classSubjects)
        ));

        if ($alreadyReleased >= count($classSubjects)) {
            return $this->redirectToRoute('tenant_approval_hub');
        }

        // Publish once per **class**, not once per subject. The first version
        // called this for all five subjects, so a 67-pupil class sent 335 mails
        // inside one request and the release died on the 30-second limit.
        $published = [];
        $notSent = 0;
        foreach ($classSubjects as $classSubject) {
            $classroomId = $classSubject->getClassroom()?->getId();
            if ($classroomId === null || isset($published[$classroomId])) {
                continue;
            }
            $published[$classroomId] = true;

            $approval = $this->em->getRepository(ResultApproval::class)->findOneBy([
                'classSubject' => $classSubject,
                'term' => $term,
            ]);
            if ($approval) {
                $notSent += $this->publishResultsIfComplete($approval, $mailer);
            }
        }

        // Telling a parent is a separate job from releasing the marks. If the
        // queue is unavailable the release still stands — say so instead of
        // pretending every family was notified.
        if ($notSent > 0) {
            $this->addFlash('warning', sprintf(
                '%d of the notifications could not be queued — check the mail settings and the worker.',
                $notSent
            ));
        }

        return $this->redirectToRoute('tenant_approval_hub');
    }

    /**
     * "Publish" a class's results once nothing is left to sign off.
     *
     * A class has one approval row per subject, so emailing on every approval
     * would spam each parent once per subject on the timetable. Instead the
     * parents hear about it exactly once — when the last subject clears.
     */
    /** @return int number of notifications that could not be queued */
    private function publishResultsIfComplete(ResultApproval $approval, SchoolMailer $mailer): int
    {
        $classSubject = $approval->getClassSubject();
        $classroom = $classSubject?->getClassroom();
        $session = $classSubject?->getSession();
        $term = $approval->getTerm();

        if ($classroom === null || $session === null || $term === null) {
            return 0;
        }

        $outstanding = (int) $this->em->createQueryBuilder()
            ->select('COUNT(ra.id)')
            ->from(ResultApproval::class, 'ra')
            ->join('ra.classSubject', 'cs')
            ->andWhere('cs.classroom = :classroom')
            ->andWhere('cs.session = :session')
            ->andWhere('ra.term = :term')
            ->andWhere('ra.yearGroupStatus != :approved')
            ->setParameter('classroom', $classroom)
            ->setParameter('session', $session)
            ->setParameter('term', $term)
            ->setParameter('approved', ResultApproval::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        if ($outstanding > 0) {
            return 0;
        }

        $school = $this->em->getRepository(School::class)->findOneBy([]);
        if ($school === null) {
            return 0;
        }

        // Parents reach their children's results at /reports/student.
        $url = $this->generateUrl(
            'app_tenant_report_student',
            [],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        );

        $failed = 0;
        foreach ($this->em->getRepository(Student::class)->findBy(['currentClass' => $classroom]) as $student) {
            if ($student->getGuardian()?->getEmail() === null) {
                continue;
            }

            // Queued, not sent: one message per family, delivered by the worker.
            if (!$mailer->resultPublished($school, $student, $term, $url)) {
                $failed++;
            }
        }

        return $failed;
    }

    #[Route('/{id}/unlock', name: 'tenant_approval_unlock', methods: ['POST'])]
    public function unlock(ResultApproval $approval, Request $request): Response
    {
        $this->assertCsrf($request);
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

        // Approving a class needs to show *which* subjects are actually waiting.
        // The stage status alone cannot: it reads "pending" both for a sheet the
        // teacher submitted and for one nobody has touched.
        $submitted = 0;
        if ($term) {
            $submitted = (int) $this->em->getRepository(ResultComponentScore::class)->count([
                'classSubject' => $classSubject,
                'term' => $term,
                'status' => ResultComponentScore::STATUS_SUBMITTED,
            ]);
        }

        return [
            'classSubject' => $classSubject,
            'approval' => $approval,
            'teacher' => $teacher,
            'submitted' => $submitted > 0,
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

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('approval_action', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('That form expired. Reload the page and try again.');
        }
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
