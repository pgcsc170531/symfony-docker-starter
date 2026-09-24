<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\ResultComponentScore;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Subject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use App\Entity\Tenant\YearGroupMaster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reports')]
#[IsGranted('ROLE_USER')]
class ResultReportController extends AbstractController
{
    private ?array $cachedGradingScales = null;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'app_tenant_reports_hub')]
    #[IsGranted('ROLE_TEACHER')]
    public function hub(): Response
    {
        $staff = $this->currentStaff();

        return $this->render('tenant/reports/hub.html.twig', [
            'isAdmin' => $this->isGranted('ROLE_ADMIN'),
            'hasFormTeacher' => $staff && $this->em->getRepository(FormTeacher::class)->findOneBy(['staff' => $staff]),
            'hasHod' => $staff && $this->em->getRepository(DepartmentHead::class)->findOneBy(['staff' => $staff]),
            'hasYgm' => $staff && $this->em->getRepository(YearGroupMaster::class)->findOneBy(['staff' => $staff]),
        ]);
    }

    // ------------------------------------------------------------
    // 20. Teacher report
    // ------------------------------------------------------------

    #[Route('/teacher', name: 'app_tenant_report_teacher_index')]
    #[IsGranted('ROLE_TEACHER')]
    public function teacherIndex(): Response
    {
        $staff = $this->getStaff();
        $assignments = $this->em->getRepository(SubjectTeacher::class)->findBy(['staff' => $staff], ['term' => 'ASC']);

        return $this->render('tenant/reports/teacher_index.html.twig', ['assignments' => $assignments]);
    }

    #[Route('/teacher/{id}', name: 'app_tenant_report_teacher_show')]
    #[IsGranted('ROLE_TEACHER')]
    public function teacherShow(SubjectTeacher $assignment): Response
    {
        $this->assertOwnAssignment($assignment);

        $classSubject = $this->resolveClassSubject($assignment);
        if (!$classSubject) {
            throw $this->createNotFoundException('This subject has not been registered for the class.');
        }

        $matrix = $this->scoresByStudent($classSubject, $assignment->getTerm());
        $components = $this->componentsFor($classSubject, $assignment->getTerm());

        // Sort students by total descending.
        uasort($matrix, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $this->render('tenant/reports/teacher_show.html.twig', [
            'assignment' => $assignment,
            'classSubject' => $classSubject,
            'matrix' => $matrix,
            'components' => $components,
        ]);
    }

    // ------------------------------------------------------------
    // 21. Form Teacher broadsheet
    // ------------------------------------------------------------

    #[Route('/form-teacher', name: 'app_tenant_report_form_teacher_index')]
    #[IsGranted('ROLE_TEACHER')]
    public function formTeacherIndex(): Response
    {
        $staff = $this->getStaff();
        $assignments = $this->em->getRepository(FormTeacher::class)->findBy(['staff' => $staff], ['term' => 'ASC']);

        return $this->render('tenant/reports/form_teacher_index.html.twig', ['assignments' => $assignments]);
    }

    #[Route('/form-teacher/{id}', name: 'app_tenant_report_form_teacher_show')]
    #[IsGranted('ROLE_TEACHER')]
    public function formTeacherShow(FormTeacher $assignment): Response
    {
        $staff = $this->getStaff();
        if ($assignment->getStaff()->getId() !== $staff->getId() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $term = $assignment->getTerm();
        $classSubjects = $this->em->getRepository(ClassSubject::class)->findBy([
            'classroom' => $assignment->getClassroom(),
            'session' => $term->getSession(),
        ]);

        $students = $this->em->getRepository(Student::class)->findBy(
            ['currentClassroom' => $assignment->getClassroom()],
            ['lastName' => 'ASC', 'firstName' => 'ASC']
        );

        $totals = [];
        foreach ($classSubjects as $cs) {
            $matrix = $this->scoresByStudent($cs, $term);
            foreach ($matrix as $sid => $entry) {
                $totals[$sid][$cs->getId()] = ['total' => $entry['total'], 'grade' => $entry['grade']];
            }
        }

        $rows = [];
        foreach ($students as $student) {
            $subjectTotals = $totals[$student->getId()] ?? [];
            $sum = 0.0;
            $count = 0;
            foreach ($subjectTotals as $cell) {
                $sum += $cell['total'];
                $count++;
            }
            $rows[] = [
                'student' => $student,
                'subjects' => $subjectTotals,
                'average' => $count ? round($sum / $count, 2) : null,
            ];
        }

        usort($rows, fn ($a, $b) => ($b['average'] ?? -1) <=> ($a['average'] ?? -1));

        return $this->render('tenant/reports/form_teacher_show.html.twig', [
            'assignment' => $assignment,
            'classSubjects' => $classSubjects,
            'rows' => $rows,
        ]);
    }

    // ------------------------------------------------------------
    // 22. HOD subject master
    // ------------------------------------------------------------

    #[Route('/hod', name: 'app_tenant_report_hod_index')]
    #[IsGranted('ROLE_TEACHER')]
    public function hodIndex(): Response
    {
        $staff = $this->getStaff();
        $hodAssignments = $this->em->getRepository(DepartmentHead::class)->findBy(['staff' => $staff]);

        $departments = [];
        foreach ($hodAssignments as $hod) {
            $subjects = $this->em->getRepository(Subject::class)->findBy(['department' => $hod->getDepartment()], ['name' => 'ASC']);
            $departments[] = [
                'assignment' => $hod,
                'subjects' => $subjects,
            ];
        }

        return $this->render('tenant/reports/hod_index.html.twig', ['departments' => $departments]);
    }

    #[Route('/hod/{id}', name: 'app_tenant_report_hod_show')]
    #[IsGranted('ROLE_TEACHER')]
    public function hodShow(Subject $subject, Request $request): Response
    {
        $staff = $this->getStaff();
        $department = $subject->getDepartment();
        if (!$department) {
            throw $this->createAccessDeniedException();
        }

        $hod = $this->em->getRepository(DepartmentHead::class)->findOneBy([
            'staff' => $staff,
            'department' => $department,
        ]);
        if (!$hod && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $sessionId = $request->query->get('session');
        $session = $sessionId ? $this->em->getRepository(Session::class)->find($sessionId) : $hod?->getSession();
        $term = $session ? $this->activeTerm($session) : null;

        $classSubjects = $this->em->getRepository(ClassSubject::class)->findBy([
            'subject' => $subject,
            'session' => $session,
        ], ['classroom' => 'ASC']);

        $groups = [];
        foreach ($classSubjects as $cs) {
            $matrix = $this->scoresByStudent($cs, $term);
            uasort($matrix, fn ($a, $b) => $b['total'] <=> $a['total']);
            $groups[] = [
                'classSubject' => $cs,
                'term' => $term,
                'matrix' => $matrix,
                'average' => $matrix ? round(array_sum(array_column($matrix, 'total')) / count($matrix), 2) : null,
            ];
        }

        return $this->render('tenant/reports/hod_show.html.twig', [
            'subject' => $subject,
            'session' => $session,
            'groups' => $groups,
        ]);
    }

    // ------------------------------------------------------------
    // 23. Year Group Master status board
    // ------------------------------------------------------------

    #[Route('/year-group', name: 'app_tenant_report_year_group_index')]
    #[IsGranted('ROLE_TEACHER')]
    public function yearGroupIndex(): Response
    {
        $staff = $this->getStaff();
        $assignments = $this->em->getRepository(YearGroupMaster::class)->findBy(['staff' => $staff]);

        $groups = [];
        foreach ($assignments as $ygm) {
            $term = $this->activeTerm($ygm->getSession());
            $classrooms = $this->em->getRepository(Classroom::class)->findBy(['yearGroup' => $ygm->getYearGroup()]);
            $classSubjects = $classrooms
                ? $this->em->getRepository(ClassSubject::class)->findBy(['classroom' => $classrooms, 'session' => $ygm->getSession()])
                : [];

            $items = [];
            foreach ($classSubjects as $cs) {
                $approval = $this->approvalFor($cs, $term);
                $matrix = $this->scoresByStudent($cs, $term);
                $items[] = [
                    'classSubject' => $cs,
                    'approval' => $approval,
                    'average' => $matrix ? round(array_sum(array_column($matrix, 'total')) / count($matrix), 2) : null,
                ];
            }

            $groups[] = [
                'assignment' => $ygm,
                'term' => $term,
                'items' => $items,
            ];
        }

        return $this->render('tenant/reports/year_group.html.twig', ['groups' => $groups]);
    }

    // ------------------------------------------------------------
    // 24. Admin dashboard
    // ------------------------------------------------------------

    #[Route('/admin', name: 'app_tenant_report_admin_index')]
    #[IsGranted('ROLE_ADMIN')]
    public function adminIndex(Request $request): Response
    {
        $sessions = $this->em->getRepository(Session::class)->findBy([], ['id' => 'DESC']);
        $sessionId = $request->query->get('session');
        $session = $sessionId ? $this->em->getRepository(Session::class)->find($sessionId) : ($sessions[0] ?? null);
        $term = $session ? $this->activeTerm($session) : null;

        $rows = [];
        if ($term) {
            $classSubjects = $this->em->getRepository(ClassSubject::class)->findBy(['session' => $session], ['classroom' => 'ASC']);
            foreach ($classSubjects as $cs) {
                $matrix = $this->scoresByStudent($cs, $term);
                $distribution = array_fill_keys(['A1', 'B2', 'B3', 'C4', 'C5', 'C6', 'D7', 'E8', 'F9'], 0);
                foreach ($matrix as $entry) {
                    $distribution[$entry['grade']] = ($distribution[$entry['grade']] ?? 0) + 1;
                }
                $rows[] = [
                    'classSubject' => $cs,
                    'count' => count($matrix),
                    'average' => $matrix ? round(array_sum(array_column($matrix, 'total')) / count($matrix), 2) : null,
                    'distribution' => $distribution,
                ];
            }
        }

        return $this->render('tenant/reports/admin.html.twig', [
            'sessions' => $sessions,
            'session' => $session,
            'rows' => $rows,
        ]);
    }

    // ------------------------------------------------------------
    // 25. Student / Parent result sheet
    // ------------------------------------------------------------

    #[Route('/student', name: 'app_tenant_report_student')]
    public function studentSheet(): Response
    {
        $students = $this->studentsForCurrentUser();
        if (!$students) {
            throw $this->createAccessDeniedException('No student records are linked to your account.');
        }

        $result = [];
        foreach ($students as $student) {
            $scores = $this->em->getRepository(ResultComponentScore::class)->findBy(['student' => $student], ['term' => 'ASC']);
            $byTerm = [];
            foreach ($scores as $score) {
                $key = $score->getTerm()->getId();
                $byTerm[$key]['term'] = $score->getTerm();
                $csId = $score->getClassSubject()->getId();
                $byTerm[$key]['subjects'][$csId]['classSubject'] = $score->getClassSubject();
                $byTerm[$key]['subjects'][$csId]['components'][$score->getComponent()] = (float) $score->getScore();
            }
            foreach ($byTerm as &$t) {
                foreach ($t['subjects'] as &$s) {
                    $s['total'] = array_sum($s['components']);
                    $s['grade'] = $this->gradeFor($s['total']);
                }
                unset($s);
            }
            unset($t);

            $result[] = ['student' => $student, 'terms' => $byTerm];
        }

        return $this->render('tenant/reports/student.html.twig', ['result' => $result]);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function getStaff(): Staff
    {
        $staff = $this->currentStaff();
        if (!$staff) {
            throw $this->createAccessDeniedException('No staff profile linked to your account.');
        }

        return $staff;
    }

    private function currentStaff(): ?Staff
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $user->getStaff();
    }

    private function studentsForCurrentUser(): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return [];
        }

        if ($user->getStudent()) {
            return [$user->getStudent()];
        }

        $guardian = $this->em->getRepository(Guardian::class)->findOneBy(['user' => $user]);
        if ($guardian) {
            return $guardian->getStudents()->toArray();
        }

        return [];
    }

    private function assertOwnAssignment(SubjectTeacher $assignment): void
    {
        $staff = $this->currentStaff();
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }
        if (!$staff || $assignment->getStaff()->getId() !== $staff->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function resolveClassSubject(SubjectTeacher $assignment): ?ClassSubject
    {
        return $this->em->getRepository(ClassSubject::class)->findOneBy([
            'subject' => $assignment->getSubject(),
            'classroom' => $assignment->getClassroom(),
            'session' => $assignment->getTerm()->getSession(),
        ]);
    }

    private function activeTerm(Session $session): ?Term
    {
        $term = $this->em->getRepository(Term::class)->findOneBy(['session' => $session, 'isActive' => true], ['id' => 'ASC']);
        if (!$term) {
            $term = $this->em->getRepository(Term::class)->findOneBy(['session' => $session], ['id' => 'DESC']);
        }

        return $term;
    }

    /**
     * @return array<int, array{student: Student, components: array<string, float>, total: float, grade: string}>
     */
    private function scoresByStudent(ClassSubject $classSubject, ?Term $term): array
    {
        if (!$term) {
            return [];
        }

        $rows = $this->em->getRepository(ResultComponentScore::class)->findBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);

        $byStudent = [];
        foreach ($rows as $row) {
            $sid = $row->getStudent()->getId();
            $byStudent[$sid]['student'] = $row->getStudent();
            $byStudent[$sid]['components'][$row->getComponent()] = (float) $row->getScore();
        }
        foreach ($byStudent as &$entry) {
            $entry['total'] = array_sum($entry['components']);
            $entry['grade'] = $this->gradeFor($entry['total']);
        }
        unset($entry);

        return $byStudent;
    }

    private function componentsFor(ClassSubject $classSubject, Term $term): array
    {
        $rows = $this->em->getRepository(ResultComponentScore::class)->findBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);

        $components = [];
        foreach ($rows as $row) {
            $components[$row->getComponent()] = true;
        }

        $order = ['ca1', 'ca2', 'ca3', 'theory', 'practical'];
        $sorted = array_intersect($order, array_keys($components));

        return array_values($sorted);
    }

    private function approvalFor(ClassSubject $classSubject, ?Term $term): ?ResultApproval
    {
        if (!$term) {
            return null;
        }

        return $this->em->getRepository(ResultApproval::class)->findOneBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);
    }

    private function gradeFor(float $total): string
    {
        foreach ($this->gradingScales() as $scale) {
            if ($total >= $scale->getMinScore() && $total <= $scale->getMaxScore()) {
                return (string) $scale->getGrade();
            }
        }

        // Fallback default scale.
        if ($total >= 75) {
            return 'A1';
        }
        if ($total >= 70) {
            return 'B2';
        }
        if ($total >= 60) {
            return 'B3';
        }
        if ($total >= 50) {
            return 'C4';
        }
        if ($total >= 45) {
            return 'C5';
        }
        if ($total >= 40) {
            return 'C6';
        }
        if ($total >= 35) {
            return 'D7';
        }
        if ($total >= 30) {
            return 'E8';
        }

        return 'F9';
    }

    /** @return GradingScale[] */
    private function gradingScales(): array
    {
        if ($this->cachedGradingScales === null) {
            $this->cachedGradingScales = $this->em->getRepository(GradingScale::class)->findBy([], ['minScore' => 'DESC']);
        }

        return $this->cachedGradingScales;
    }
}
