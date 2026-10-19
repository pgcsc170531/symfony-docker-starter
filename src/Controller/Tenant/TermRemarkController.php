<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentTermRemark;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use App\Service\Results\RemarkSuggestion;
use App\Service\Results\ResultSheetBuilder;
use App\Service\SchoolLife\TermRelease;
use App\Service\Wording\LearnerWording;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The pastoral layer above the form teacher's own remark.
 *
 * Form teachers write in My Class → Remarks. This screen is for HODs and the
 * principal, who usually look after several classes. Each role may edit only
 * its own paragraph; the card prints all three with the writer's name and date.
 */
#[Route('/reports/remarks')]
#[IsGranted('ROLE_TEACHER')]
final class TermRemarkController extends AbstractController
{
    private const MAX = StudentTermRemark::TEXT_MAX;

    public function __construct(
        private EntityManagerInterface $em,
        private TermRelease $release,
        private ResultSheetBuilder $sheetBuilder,
        private RemarkSuggestion $suggestions,
        private CsrfTokenManagerInterface $csrf,
        private LearnerWording $wording,
    ) {
    }

    #[Route('', name: 'app_tenant_term_remarks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);
        $staff = $this->staff();

        $roster = $this->roster($classroom, $term);
        $existing = $this->remarksFor($roster, $term);

        $pupils = [];
        foreach ($roster as $studentId => $info) {
            $remark = $existing[$studentId] ?? null;
            $pupils[] = [
                'id' => $studentId,
                'name' => trim(($info->getFirstName() ?? '') . ' ' . ($info->getLastName() ?? '')),
                'form' => $remark?->getText() !== null,
                'hod' => $remark?->getHodText() !== null,
                'principal' => $remark?->getPrincipalText() !== null,
            ];
        }

        $selectedId = $request->query->getInt('student');
        if ($selectedId <= 0 || !isset($roster[$selectedId])) {
            $selectedId = (int) ($pupils[0]['id'] ?? 0);
        }

        $selectedStudent = $roster[$selectedId] ?? null;
        $remark = $existing[$selectedId] ?? null;

        $suggestion = null;
        if ($selectedStudent) {
            $sheet = $this->sheetBuilder->build($selectedStudent, $term);
            $suggestion = $this->suggestions->for(
                $selectedStudent->getFirstName(),
                $sheet['summary']['percentage'] ?? null
            );
        }

        $school = $this->em->getRepository(School::class)->findOneBy([]);
        $canForm = $this->isGranted('ROLE_ADMIN') || $this->formTeacherClassroom($term)?->getId() === $classroom->getId();
        $canHod = $this->isGranted('ROLE_ADMIN') || $this->isHodFor($classroom, $term);
        $canPrincipal = $this->isGranted('ROLE_ADMIN');

        $ids = array_keys($roster);
        $index = array_search($selectedId, $ids, true);
        $nextId = $ids === [] ? $selectedId : $ids[((int) $index + 1) % count($ids)];

        return $this->render('tenant/reports/remarks.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'pupils' => $pupils,
            'selectedId' => $selectedId,
            'nextId' => $nextId,
            'selectedStudent' => $selectedStudent,
            'remark' => $remark,
            'suggestion' => $suggestion,
            'school' => $school,
            'canForm' => $canForm,
            'canHod' => $canHod,
            'canPrincipal' => $canPrincipal,
            'locked' => $this->release->isReleased($classroom, $term),
            'maxLength' => self::MAX,
            'csrf' => $this->csrf->getToken('term_remark_save')->getValue(),
        ]);
    }

    #[Route('/{student}', name: 'app_tenant_term_remark_save', methods: ['POST'], requirements: ['student' => '\d+'])]
    public function save(Student $student, Request $request): JsonResponse
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);

        if ($this->release->isReleased($classroom, $term)) {
            return $this->json(['success' => false, 'error' => 'This term is released. An admin must reopen it.'], 409);
        }

        if (!array_key_exists((int) $student->getId(), $this->roster($classroom, $term))) {
            return $this->json(['success' => false, 'error' => 'That ' . $this->wording . ' is not in this class.'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true) ?: [];
        if (!$this->isCsrfTokenValid('term_remark_save', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'That form expired. Reload the page.'], 400);
        }

        $field = (string) ($payload['field'] ?? '');
        $text = trim((string) ($payload['text'] ?? ''));

        if (mb_strlen($text) > self::MAX) {
            return $this->json(['success' => false, 'error' => 'Keep the remark under ' . self::MAX . ' characters.'], 400);
        }

        if ($field === 'form' && !($this->isGranted('ROLE_ADMIN') || $this->formTeacherClassroom($term)?->getId() === $classroom->getId())) {
            return $this->json(['success' => false, 'error' => 'Only the form teacher may write this remark.'], 403);
        }
        if ($field === 'hod' && !($this->isGranted('ROLE_ADMIN') || $this->isHodFor($classroom, $term))) {
            return $this->json(['success' => false, 'error' => 'Only the HOD for this class may write this remark.'], 403);
        }
        if ($field === 'principal' && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['success' => false, 'error' => 'Only the principal or an administrator may write this remark.'], 403);
        }
        if (!in_array($field, ['form', 'hod', 'principal'], true)) {
            return $this->json(['success' => false, 'error' => 'Unknown remark type.'], 400);
        }

        $remark = $this->em->getRepository(StudentTermRemark::class)->findOneBy([
            'student' => $student,
            'term' => $term,
        ]);

        if (!$remark) {
            $remark = (new StudentTermRemark())
                ->setStudent($student)
                ->setTerm($term);
            $this->em->persist($remark);
        }

        $actor = $this->getUser()?->getUserIdentifier() ?? 'staff';
        match ($field) {
            'form' => $remark->setText($text)->touch($actor, $this->staff()),
            'hod' => $remark->setHodText($text)->touchHod($actor, $this->staff()),
            'principal' => $remark->setPrincipalText($text)->touchPrincipal($actor, $this->staff()),
        };

        $this->em->flush();

        return $this->json(['success' => true, 'field' => $field, 'written' => $text !== '']);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Context
    // ──────────────────────────────────────────────────────────────────

    private function activeTerm(): Term
    {
        $term = $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if (!$term) {
            throw $this->createNotFoundException('No active term. Ask an administrator to activate one.');
        }

        return $term;
    }

    private function resolveClassroom(Request $request, Term $term): Classroom
    {
        $own = $this->formTeacherClassroom($term);
        $allowed = $this->availableClasses($term);

        $requested = $request->query->getInt('classroom') ?: $request->request->getInt('classroom');
        if ($requested > 0) {
            foreach ($allowed as $classroom) {
                if ((int) $classroom->getId() === $requested) {
                    return $classroom;
                }
            }
        }

        if ($allowed !== []) {
            return $allowed[0];
        }

        throw $this->createAccessDeniedException('You have no class or department to write remarks for.');
    }

    /** @return list<Classroom> */
    private function availableClasses(Term $term): array
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']);
        }

        $classes = [];

        $own = $this->formTeacherClassroom($term);
        if ($own) {
            $classes[(int) $own->getId()] = $own;
        }

        foreach ($this->hodClassrooms($term) as $classroom) {
            $classes[(int) $classroom->getId()] = $classroom;
        }

        return array_values($classes);
    }

    private function formTeacherClassroom(Term $term): ?Classroom
    {
        $staff = $this->staff();
        if (!$staff) {
            return null;
        }

        return $this->em->getRepository(FormTeacher::class)
            ->findOneBy(['staff' => $staff, 'term' => $term])?->getClassroom();
    }

    /** @return list<Classroom> */
    private function hodClassrooms(Term $term): array
    {
        $staff = $this->staff();
        $session = $term->getSession();
        if (!$staff || !$session) {
            return [];
        }

        $departments = [];
        foreach ($this->em->getRepository(DepartmentHead::class)->findBy(['staff' => $staff, 'session' => $session]) as $head) {
            if ($head->getDepartment()) {
                $departments[(int) $head->getDepartment()->getId()] = $head->getDepartment();
            }
        }
        if ($departments === []) {
            return [];
        }

        $teachers = $this->em->getRepository(\App\Entity\Tenant\SubjectTeacher::class)
            ->findBy(['staff' => $staff, 'term' => $term]);
        $teacherClassrooms = [];
        foreach ($teachers as $assignment) {
            if ($assignment->getClassroom()) {
                $teacherClassrooms[(int) $assignment->getClassroom()->getId()] = $assignment->getClassroom();
            }
        }

        $subjects = $this->em->getRepository(\App\Entity\Tenant\Subject::class)
            ->findBy(['department' => array_values($departments)]);
        $classSubjects = $subjects === []
            ? []
            : $this->em->getRepository(ClassSubject::class)->findBy([
                'subject' => $subjects,
                'session' => $session,
            ]);

        $classes = [];
        foreach ($classSubjects as $classSubject) {
            if ($classSubject->getClassroom()) {
                $classes[(int) $classSubject->getClassroom()->getId()] = $classSubject->getClassroom();
            }
        }
        foreach ($teacherClassrooms as $id => $classroom) {
            $classes[$id] = $classroom;
        }

        return array_values($classes);
    }

    private function isHodFor(Classroom $classroom, Term $term): bool
    {
        foreach ($this->hodClassrooms($term) as $candidate) {
            if ((int) $candidate->getId() === (int) $classroom->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, Student> */
    private function roster(Classroom $classroom, Term $term): array
    {
        $session = $term->getSession();
        $out = [];

        if ($session) {
            foreach ($this->em->getRepository(Enrollment::class)->findBy([
                'classroom' => $classroom,
                'session' => $session,
            ]) as $enrollment) {
                if ($enrollment->getStudent()) {
                    $out[(int) $enrollment->getStudent()->getId()] = $enrollment->getStudent();
                }
            }
        }

        if ($out === []) {
            foreach ($this->em->getRepository(Student::class)->findBy(['currentClassroom' => $classroom]) as $student) {
                $out[(int) $student->getId()] = $student;
            }
        }

        uasort($out, static fn (Student $a, Student $b) => strcasecmp(
            ($a->getLastName() ?? '') . ' ' . ($a->getFirstName() ?? ''),
            ($b->getLastName() ?? '') . ' ' . ($b->getFirstName() ?? '')
        ));

        return $out;
    }

    /**
     * @param array<int, Student> $roster
     *
     * @return array<int, StudentTermRemark>
     */
    private function remarksFor(array $roster, Term $term): array
    {
        if ($roster === []) {
            return [];
        }

        $out = [];
        foreach ($this->em->getRepository(StudentTermRemark::class)->findBy([
            'student' => array_values($roster),
            'term' => $term,
        ]) as $remark) {
            $out[(int) $remark->getStudent()->getId()] = $remark;
        }

        return $out;
    }

    private function staff(): ?Staff
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getStaff() : null;
    }
}
