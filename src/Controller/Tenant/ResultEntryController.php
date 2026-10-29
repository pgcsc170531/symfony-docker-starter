<?php


namespace App\Controller\Tenant;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\ResultComponentScore;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentSubject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\YearGroupMaster;
use App\Service\Results\AssessmentSchemeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/tenant/results')]
#[IsGranted('ROLE_TEACHER')]
class ResultEntryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CsrfTokenManagerInterface $csrf,
        private AssessmentSchemeResolver $schemes,
    ) {
    }

    #[Route('/entry', name: 'tenant_result_entry_index')]
    public function index(): Response
    {
        // An overseer or an administrator may hold no timetable of their own.
        // The index must still open for them — it lists personal assignments,
        // and an empty list is the truth. The entry form itself stays strict:
        // `assertOwnAssignment()` still refuses a sheet that is not yours.
        $user = $this->getUser();
        $staff = $user instanceof \App\Entity\Tenant\User ? $user->getStaff() : null;

        $assignments = $staff
            ? $this->em->getRepository(SubjectTeacher::class)->findBy(['staff' => $staff], ['term' => 'ASC'])
            : [];

        return $this->render('tenant/result_entry/index.html.twig', [
            'assignments' => $assignments,
        ]);
    }

    #[Route('/entry/{id}', name: 'tenant_result_entry_form')]
    public function entry(SubjectTeacher $assignment): Response
    {
        $this->assertOwnAssignment($assignment);
        $classSubject = $this->resolveClassSubject($assignment);

        if (!$classSubject) {
            throw $this->createNotFoundException('This subject has not been registered for the class.');
        }

        $students = $this->resolveStudents($classSubject);
        $scheme = $this->resolveScheme($assignment);
        $existing = $this->em->getRepository(ResultComponentScore::class)->findBy([
            'classSubject' => $classSubject,
            'term' => $assignment->getTerm(),
        ]);

        $scoresByStudent = [];
        foreach ($existing as $score) {
            $scoresByStudent[$score->getStudent()->getId()][$score->getComponent()] = $score->getScore();
        }

        // If an HOD sent this sheet back, the teacher must be told what to fix.
        $approval = $this->em->getRepository(ResultApproval::class)->findOneBy([
            'classSubject' => $classSubject,
            'term' => $assignment->getTerm(),
        ]);
        $rejection = ($approval && $approval->getHodStatus() === ResultApproval::STATUS_REJECTED)
            ? $approval->getHodReason()
            : null;

        return $this->render('tenant/result_entry/entry.html.twig', [
            'assignment' => $assignment,
            'classSubject' => $classSubject,
            'students' => $students,
            'students_json' => $this->studentsToJson($students),
            'scheme' => $scheme,
            'scores' => $scoresByStudent,
            'gradingScale' => $this->gradingScaleJson(),
            'locked' => $this->isLocked($classSubject, $assignment->getTerm()),
            'rejection' => $rejection,
            'csrf' => $this->csrf->getToken('result_entry')->getValue(),
        ]);
    }

    #[Route('/entry/{id}/save', name: 'tenant_result_entry_save', methods: ['POST'])]
    public function save(SubjectTeacher $assignment, Request $request): JsonResponse
    {
        $this->assertOwnAssignment($assignment);
        $data = json_decode($request->getContent(), true);
        $this->assertCsrf((string) ($data['_token'] ?? ''));
        $classSubject = $this->resolveClassSubject($assignment);

        if (!$classSubject) {
            return $this->json(['success' => false, 'error' => 'Subject not registered for this class.'], 400);
        }

        if ($this->isLocked($classSubject, $assignment->getTerm())) {
            return $this->json(['success' => false, 'error' => 'Results are locked. An HOD or Admin must unlock before editing.'], 423);
        }

        $roster = $this->rosterIds($classSubject);

        // Only the components this school's scheme counts. A row posted with a
        // component that is no longer reported (a stale tab open from before the
        // school moved from three CAs to two) is ignored rather than stored.
        $allowed = $this->resolveComponents($this->resolveScheme($assignment));

        foreach ($data['scores'] ?? [] as $row) {
            $student = $this->em->getRepository(Student::class)->find($row['student_id'] ?? null);
            if (!$student || !isset($roster[(int) $student->getId()])) {
                continue;
            }

            foreach ($row as $component => $score) {
                if (!in_array($component, $allowed, true) || $score === '' || $score === null) {
                    continue;
                }

                $this->upsertScore($classSubject, $assignment, $student, $component, (string) $score);
            }
        }

        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/entry/{id}/submit', name: 'tenant_result_entry_submit', methods: ['POST'])]
    public function submit(SubjectTeacher $assignment, Request $request): JsonResponse
    {
        $this->assertOwnAssignment($assignment);
        $payload = json_decode((string) $request->getContent(), true) ?: [];
        $this->assertCsrf((string) ($payload['_token'] ?? $request->request->get('_token', '')));
        $classSubject = $this->resolveClassSubject($assignment);
        if (!$classSubject) {
            return $this->json(['success' => false, 'error' => 'Subject not registered for this class.'], 400);
        }

        $term = $assignment->getTerm();

        if ($this->isLocked($classSubject, $term)) {
            return $this->json(['success' => false, 'error' => 'Results are already submitted.'], 409);
        }

        $scores = $this->em->getRepository(ResultComponentScore::class)->findBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);

        if (!$scores) {
            return $this->json(['success' => false, 'error' => 'No scores entered to submit.'], 400);
        }

        foreach ($scores as $score) {
            $score->setStatus(ResultComponentScore::STATUS_SUBMITTED);
        }

        // Ensure an approval row exists so the result appears in the approval hub.
        $approval = $this->em->getRepository(ResultApproval::class)->findOneBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);
        if (!$approval) {
            $approval = new ResultApproval();
            $approval->setClassSubject($classSubject);
            $approval->setTerm($term);
            $this->em->persist($approval);
        }

        $this->em->flush();

        return $this->json(['success' => true]);
    }

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

    private function resolveStudents(ClassSubject $classSubject): array
    {
        if ($classSubject->isCore()) {
            return $this->em->getRepository(Student::class)
                ->findBy(['currentClassroom' => $classSubject->getClassroom()]); // ⚠️ confirm Student::getCurrentClassroom() property name
        }

        return $this->em->getRepository(Student::class)
            ->createQueryBuilder('s')
            ->join(StudentSubject::class, 'ss', 'WITH', 'ss.student = s')
            ->where('ss.classSubject = :cs')
            ->setParameter('cs', $classSubject)
            ->getQuery()
            ->getResult();
    }

    /**
     * The scheme for this teacher's sheet — graded subject → class → school, the
     * same chain the report card walks, so the fields a teacher fills are the
     * columns the report prints. A sheet with no class subject yet falls back to
     * the school's own CA count from Settings.
     */
    private function resolveScheme(SubjectTeacher $assignment): array
    {
        $classSubject = $this->resolveClassSubject($assignment);

        return $classSubject
            ? $this->schemes->resolve($assignment->getClassroom(), $classSubject, $assignment->getTerm())
            : $this->schemes->defaults();
    }

    #[Route('/entry/{id}/template', name: 'tenant_result_entry_template', methods: ['GET'])]
    public function downloadTemplate(SubjectTeacher $assignment): StreamedResponse
    {
        $this->assertOwnAssignment($assignment);
        $scheme = $this->resolveScheme($assignment);
        $components = $this->resolveComponents($scheme);

        $classSubject = $this->resolveClassSubject($assignment);
        if (!$classSubject) {
            throw $this->createNotFoundException('This subject has not been registered for the class.');
        }

        // The template is the class list, not a demo. It used to emit a single
        // fake row ("24/001 John Doe"), so a teacher who followed the handout
        // ("do not add or remove rows") imported marks for a pupil who does not
        // exist. Existing marks are pre-filled, so the file round-trips.
        $students = $this->resolveStudents($classSubject);
        usort($students, fn (Student $a, Student $b) => strcasecmp($a->getFullName(), $b->getFullName()));

        $existing = $this->em->getRepository(ResultComponentScore::class)->findBy([
            'classSubject' => $classSubject,
            'term' => $assignment->getTerm(),
        ]);
        $scoresByStudent = [];
        foreach ($existing as $score) {
            $scoresByStudent[$score->getStudent()->getId()][$score->getComponent()] = $score->getScore();
        }

        $header = array_merge(
            ['Admission No', 'Student Name'],
            array_map('strtoupper', $components)
        );

        $response = new StreamedResponse(function () use ($header, $students, $components, $scoresByStudent) {
            $handle = fopen('php://output', 'w+');
            fputcsv($handle, $header);
            foreach ($students as $student) {
                $row = [$student->getAdmissionNumber(), $student->getFullName()];
                foreach ($components as $component) {
                    $row[] = $scoresByStudent[$student->getId()][$component] ?? '';
                }
                fputcsv($handle, $row);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="result_entry_template.csv"');

        return $response;
    }

    #[Route('/entry/{id}/import', name: 'tenant_result_entry_import', methods: ['POST'])]
    public function import(SubjectTeacher $assignment, Request $request): JsonResponse
    {
        $this->assertOwnAssignment($assignment);
        $this->assertCsrf((string) $request->request->get('_token', ''));
        $classSubject = $this->resolveClassSubject($assignment);
        if (!$classSubject) {
            return $this->json(['success' => false, 'error' => 'Subject not registered for this class.'], 400);
        }

        if ($this->isLocked($classSubject, $assignment->getTerm())) {
            return $this->json(['success' => false, 'error' => 'Results are locked. An HOD or Admin must unlock before importing.'], 423);
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file || !$file->isValid()) {
            return $this->json(['success' => false, 'error' => 'No valid CSV file uploaded.'], 400);
        }

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return $this->json(['success' => false, 'error' => 'Could not read the uploaded file.'], 400);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return $this->json(['success' => false, 'error' => 'The CSV file is empty.'], 400);
        }

        $colMap = [];
        foreach ($header as $i => $col) {
            $colMap[strtolower(trim($col))] = $i;
        }

        $admissionIdx = $colMap['admission no'] ?? $colMap['admission number'] ?? null;
        if ($admissionIdx === null) {
            fclose($handle);
            return $this->json(['success' => false, 'error' => 'CSV must contain an "Admission No" column.'], 400);
        }

        $components = $this->resolveComponents($this->resolveScheme($assignment));
        $studentRepo = $this->em->getRepository(Student::class);
        $roster = $this->rosterIds($classSubject);

        $imported = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false && $row !== null) {
            $admission = trim((string) ($row[$admissionIdx] ?? ''));
            if ($admission === '') {
                continue;
            }

            $student = $studentRepo->findOneBy(['admissionNumber' => $admission]);
            if (!$student) {
                $errors[] = "Student not found for admission no: {$admission}";
                continue;
            }
            if (!isset($roster[(int) $student->getId()])) {
                $errors[] = "Student {$admission} is not in this class.";
                continue;
            }

            foreach ($components as $component) {
                if (!isset($colMap[$component])) {
                    continue;
                }
                $value = trim((string) ($row[$colMap[$component]] ?? ''));
                if ($value === '') {
                    continue;
                }
                $this->upsertScore($classSubject, $assignment, $student, $component, $value);
                $imported++;
            }
        }

        fclose($handle);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'imported' => $imported,
            'errors' => $errors,
        ]);
    }

    private function resolveClassSubject(SubjectTeacher $assignment): ?ClassSubject
    {
        return $this->em->getRepository(ClassSubject::class)->findOneBy([
            'subject' => $assignment->getSubject(),
            'classroom' => $assignment->getClassroom(),
            'session' => $assignment->getTerm()->getSession(),
        ]);
    }

    /**
     * A subject teacher may only open the assignment the admin gave them.
     * Admins keep a bypass because they are the ones who repair timetables.
     */
    /**
     * Read-only view of one class subject's sheet.
     *
     * Reviewing results is not the same job as entering them. An HOD, the form
     * teacher or the year group master has to be able to *read* a submitted sheet
     * before approving it, and none of them may change a mark. This route is that
     * view: every reviewer who is entitled to the sheet can open it, and the page
     * has no inputs at all.
     */
    #[Route('/sheet/{id}', name: 'tenant_result_sheet_view', methods: ['GET'])]
    public function sheet(ClassSubject $classSubject): Response
    {
        $this->assertCanReviewSheet($classSubject);

        $term = $this->em->getRepository(Term::class)->findOneBy(['isActive' => true])
            ?? $this->em->getRepository(Term::class)->findOneBy(['session' => $classSubject->getSession()]);

        if (!$term) {
            throw $this->createNotFoundException('No term is open for this class.');
        }

        $assignment = $this->em->getRepository(SubjectTeacher::class)->findOneBy([
            'subject' => $classSubject->getSubject(),
            'classroom' => $classSubject->getClassroom(),
            'term' => $term,
        ]);

        $scheme = $assignment
            ? $this->resolveScheme($assignment)
            : $this->schemes->defaults();

        $students = $this->resolveStudents($classSubject);
        usort($students, static fn (Student $a, Student $b) => strcasecmp($a->getFullName(), $b->getFullName()));

        $rows = $this->em->getRepository(ResultComponentScore::class)->findBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);

        $scores = [];
        $submitted = false;
        foreach ($rows as $row) {
            $scores[$row->getStudent()->getId()][$row->getComponent()] = (float) $row->getScore();
            $submitted = $submitted || $row->getStatus() === ResultComponentScore::STATUS_SUBMITTED;
        }

        $components = $this->resolveComponents($scheme);

        $grading = $this->gradingScaleJson();

        $sheet = [];
        $totals = [];
        foreach ($students as $student) {
            $pupilScores = $scores[$student->getId()] ?? [];
            $marked = array_intersect_key($pupilScores, array_flip($components));

            $total = $marked === [] ? null : array_sum($marked);
            $grade = $total === null ? null : $this->gradeFor($total, $grading);

            if ($total !== null) {
                $totals[] = $total;
            }

            $sheet[] = [
                'student' => $student,
                'scores' => $pupilScores,
                'total' => $total,
                'grade' => $grade,
            ];
        }

        // "How many credits" is the question an HOD actually asks a sheet:
        // A1–C6 credit, D7–E8 pass, F9 fail.
        $credits = 0;
        $passes = 0;
        $fails = 0;
        foreach ($sheet as $row) {
            if ($row['grade'] === null) {
                continue;
            }
            if (in_array($row['grade'], ['A1', 'B2', 'B3', 'C4', 'C5', 'C6'], true)) {
                $credits++;
            } elseif (in_array($row['grade'], ['D7', 'E8'], true)) {
                $passes++;
            } else {
                $fails++;
            }
        }

        $approval = $this->em->getRepository(ResultApproval::class)->findOneBy([
            'classSubject' => $classSubject,
            'term' => $term,
        ]);

        return $this->render('tenant/result_entry/sheet.html.twig', [
            'classSubject' => $classSubject,
            'term' => $term,
            'components' => $components,
            'sheet' => $sheet,
            'graded' => count($totals),
            'roster' => count($sheet),
            'average' => $totals === [] ? null : array_sum($totals) / count($totals),
            'credits' => $credits,
            'passes' => $passes,
            'fails' => $fails,
            'submitted' => $submitted,
            'approval' => $approval,
        ]);
    }

    private function gradeFor(float $total, array $grading): string
    {
        foreach ($grading as $scale) {
            if ($total >= $scale['min'] && $total <= $scale['max']) {
                return $scale['grade'];
            }
        }

        return match (true) {
            $total >= 75 => 'A1',
            $total >= 70 => 'B2',
            $total >= 60 => 'B3',
            $total >= 50 => 'C4',
            $total >= 45 => 'C5',
            $total >= 40 => 'C6',
            $total >= 35 => 'D7',
            $total >= 30 => 'E8',
            default => 'F9',
        };
    }

    /**
     * Who may read a class subject's sheet: the teacher who owns it, the class's
     * form teacher, the HOD of its department, the year group master — and an
     * admin. Nobody else, and nobody may edit it from here.
     */
    private function assertCanReviewSheet(ClassSubject $classSubject): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        $staff = $this->getStaff();
        $term = $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        $owns = $term && $this->em->getRepository(SubjectTeacher::class)->findOneBy([
            'subject' => $classSubject->getSubject(),
            'classroom' => $classSubject->getClassroom(),
            'term' => $term,
            'staff' => $staff,
        ]);

        $isFormTeacher = (bool) $this->em->getRepository(FormTeacher::class)->findOneBy([
            'classroom' => $classSubject->getClassroom(),
            'staff' => $staff,
        ]);

        $department = $classSubject->getSubject()?->getDepartment();
        $isHod = $department && $this->em->getRepository(DepartmentHead::class)->findOneBy([
            'department' => $department,
            'staff' => $staff,
        ]);

        $yearGroup = $classSubject->getClassroom()?->getYearGroup();
        $isYearGroupMaster = $yearGroup && $this->em->getRepository(YearGroupMaster::class)->findOneBy([
            'yearGroup' => $yearGroup,
            'staff' => $staff,
        ]);

        if (!$owns && !$isFormTeacher && !$isHod && !$isYearGroupMaster) {
            throw $this->createAccessDeniedException('You do not review this class subject.');
        }
    }

    private function assertOwnAssignment(SubjectTeacher $assignment): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        if ($assignment->getStaff()?->getId() !== $this->getStaff()->getId()) {
            throw $this->createAccessDeniedException('This subject assignment belongs to another teacher.');
        }
    }

    private function assertCsrf(string $token): void
    {
        if (!$this->isCsrfTokenValid('result_entry', $token)) {
            throw $this->createAccessDeniedException('That form expired. Reload the page and try again.');
        }
    }

    /**
     * The pupils a class subject is allowed to receive scores for.
     *
     * @return array<int, true>
     */
    private function rosterIds(ClassSubject $classSubject): array
    {
        $ids = [];
        foreach ($this->resolveStudents($classSubject) as $student) {
            $ids[(int) $student->getId()] = true;
        }

        return $ids;
    }

    private function isLocked(ClassSubject $classSubject, Term $term): bool
    {
        return (bool) $this->em->getRepository(ResultComponentScore::class)->findOneBy([
            'classSubject' => $classSubject,
            'term' => $term,
            'status' => ResultComponentScore::STATUS_SUBMITTED,
        ]);
    }

    private function upsertScore(ClassSubject $classSubject, SubjectTeacher $assignment, Student $student, string $component, string $score): void
    {
        $existing = $this->em->getRepository(ResultComponentScore::class)->findOneBy([
            'student' => $student,
            'classSubject' => $classSubject,
            'term' => $assignment->getTerm(),
            'component' => $component,
        ]);

        if (!$existing) {
            $existing = new ResultComponentScore();
            $existing->setStudent($student)
                ->setClassSubject($classSubject)
                ->setTerm($assignment->getTerm())
                ->setComponent($component);
            $existing->setCreatedAt(new \DateTimeImmutable());
        }

        $existing->setScore($score);
        $existing->setEnteredBy($this->getStaff());
        $existing->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($existing);
    }

    private function resolveComponents(array $scheme): array
    {
        return $this->schemes->components($scheme);
    }

    private function studentsToJson(array $students): array
    {
        $out = [];
        foreach ($students as $student) {
            $out[] = [
                'id' => $student->getId(),
                'name' => $student->getFullName(),
                'admission_no' => $student->getAdmissionNumber(),
            ];
        }

        return $out;
    }

    private function gradingScaleJson(): array
    {
        $scales = $this->em->getRepository(GradingScale::class)->findBy([], ['minScore' => 'DESC']);

        $out = [];
        foreach ($scales as $scale) {
            $out[] = [
                'grade' => $scale->getGrade(),
                'min' => $scale->getMinScore(),
                'max' => $scale->getMaxScore(),
            ];
        }

        return $out;
    }
}