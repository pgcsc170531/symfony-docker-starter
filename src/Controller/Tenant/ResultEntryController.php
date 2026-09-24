<?php


namespace App\Controller\Tenant;

use App\Entity\Tenant\AssessmentScheme;
use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\ResultComponentScore;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentSubject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/tenant/results')]
#[IsGranted('ROLE_TEACHER')]
class ResultEntryController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/entry', name: 'tenant_result_entry_index')]
    public function index(): Response
    {
        $staff = $this->getStaff();
        $assignments = $this->em->getRepository(SubjectTeacher::class)
            ->findBy(['staff' => $staff], ['term' => 'ASC']);

        return $this->render('tenant/result_entry/index.html.twig', [
            'assignments' => $assignments,
        ]);
    }

    #[Route('/entry/{id}', name: 'tenant_result_entry_form')]
    public function entry(SubjectTeacher $assignment): Response
    {
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

        return $this->render('tenant/result_entry/entry.html.twig', [
            'assignment' => $assignment,
            'classSubject' => $classSubject,
            'students' => $students,
            'students_json' => $this->studentsToJson($students),
            'scheme' => $scheme,
            'scores' => $scoresByStudent,
            'gradingScale' => $this->gradingScaleJson(),
            'locked' => $this->isLocked($classSubject, $assignment->getTerm()),
        ]);
    }

    #[Route('/entry/{id}/save', name: 'tenant_result_entry_save', methods: ['POST'])]
    public function save(SubjectTeacher $assignment, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $classSubject = $this->resolveClassSubject($assignment);

        if (!$classSubject) {
            return $this->json(['success' => false, 'error' => 'Subject not registered for this class.'], 400);
        }

        if ($this->isLocked($classSubject, $assignment->getTerm())) {
            return $this->json(['success' => false, 'error' => 'Results are locked. An HOD or Admin must unlock before editing.'], 423);
        }

        foreach ($data['scores'] ?? [] as $row) {
            $student = $this->em->getRepository(Student::class)->find($row['student_id'] ?? null);
            if (!$student) {
                continue;
            }

            foreach ($row as $component => $score) {
                if (!in_array($component, ['ca1', 'ca2', 'ca3', 'theory', 'practical'], true) || $score === '' || $score === null) {
                    continue;
                }

                $this->upsertScore($classSubject, $assignment, $student, $component, (string) $score);
            }
        }

        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/entry/{id}/submit', name: 'tenant_result_entry_submit', methods: ['POST'])]
    public function submit(SubjectTeacher $assignment): JsonResponse
    {
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

    private function resolveScheme(SubjectTeacher $assignment): array
    {
        $term = $assignment->getTerm();
        $repo = $this->em->getRepository(AssessmentScheme::class);

        $scheme = $repo->findOneBy([
            'term' => $term,
            'classroom' => $assignment->getClassroom(),
            'subject' => $assignment->getSubject(),
        ]) ?? $repo->findOneBy([
            'term' => $term,
            'classroom' => $assignment->getClassroom(),
            'subject' => null,
        ]) ?? $repo->findOneBy([
            'term' => $term,
            'classroom' => null,
            'subject' => null,
        ]);

        if (!$scheme) {
            return [
                'ca_count' => 3,
                'exam_mode' => 'single',
                'ca_max' => 20,
                'theory_max' => 60,
                'practical_max' => 40,
            ];
        }

        return [
            'ca_count' => $scheme->getCaCount(),
            'exam_mode' => $scheme->getExamMode(),
            'ca_max' => $scheme->getCaMax(),
            'theory_max' => $scheme->getTheoryMax(),
            'practical_max' => $scheme->getPracticalMax(),
        ];
    }

    #[Route('/entry/{id}/template', name: 'tenant_result_entry_template', methods: ['GET'])]
    public function downloadTemplate(SubjectTeacher $assignment): StreamedResponse
    {
        $scheme = $this->resolveScheme($assignment);
        $components = $this->resolveComponents($scheme);

        $header = array_merge(
            ['Admission No', 'Student Name'],
            array_map('strtoupper', $components)
        );
        $sample = array_merge(
            ['24/001', 'John Doe'],
            array_fill(0, count($components), '0')
        );

        $response = new StreamedResponse(function () use ($header, $sample) {
            $handle = fopen('php://output', 'w+');
            fputcsv($handle, $header);
            fputcsv($handle, $sample);
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="result_entry_template.csv"');

        return $response;
    }

    #[Route('/entry/{id}/import', name: 'tenant_result_entry_import', methods: ['POST'])]
    public function import(SubjectTeacher $assignment, Request $request): JsonResponse
    {
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
        $components = [];
        for ($i = 1; $i <= ($scheme['ca_count'] ?? 3); $i++) {
            $components[] = 'ca' . $i;
        }
        $components[] = 'theory';
        if (($scheme['exam_mode'] ?? 'single') === 'dual') {
            $components[] = 'practical';
        }

        return $components;
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