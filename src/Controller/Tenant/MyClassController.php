<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\AttendanceChange;
use App\Entity\Tenant\AttendanceEntry;
use App\Entity\Tenant\AttendanceRegister;
use App\Entity\Tenant\BehaviourRating;
use App\Entity\Tenant\BehaviourTrait;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentTermRemark;
use App\Entity\Tenant\Term;
use App\Service\Results\RemarkSuggestion;
use App\Service\Results\ResultSheetBuilder;
use App\Service\SchoolLife\AttendanceSummary;
use App\Service\SchoolLife\BehaviourTraitSeeder;
use App\Service\SchoolLife\SchoolCalendar;
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
 * "My Class" — the form master's own workspace.
 *
 * Attendance and behaviour are the same feature twice: same owner (form master),
 * same scope (one class, one term), same shape (pupils × days, pupils × traits),
 * same bulk-entry pattern, same lock. So they share one home instead of two.
 *
 * Design: docs/school-life/ATTENDANCE-AND-BEHAVIOUR-DESIGN.md
 */
#[Route('/my-class')]
#[IsGranted('ROLE_TEACHER')]
final class MyClassController extends AbstractController
{
    private const SLOT = AttendanceRegister::SLOT_FULL;

    /** The card has room for two printed lines, not an essay. */
    private const REMARK_MAX = StudentTermRemark::TEXT_MAX;

    public function __construct(
        private EntityManagerInterface $em,
        private SchoolCalendar $calendar,
        private AttendanceSummary $attendanceSummary,
        private TermRelease $release,
        private BehaviourTraitSeeder $traitSeeder,
        private CsrfTokenManagerInterface $csrf,
        private ResultSheetBuilder $sheetBuilder,
        private RemarkSuggestion $suggestions,
        private LearnerWording $wording,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────
    //  Hub
    // ──────────────────────────────────────────────────────────────────

    #[Route('', name: 'app_tenant_my_class', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);
        $this->traitSeeder->ensureSeeded();

        $registerDates = $this->registerDates($classroom, $term);
        $schoolDays = array_keys($this->calendar->schoolDays($term));
        $taken = count(array_intersect($schoolDays, array_keys($registerDates)));

        $roster = $this->roster($classroom, $term);
        $traits = $this->activeTraits();
        $ratedPupils = $this->ratedPupilCount($classroom, $term, $roster, $traits);

        return $this->render('tenant/my_class/index.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'isFormTeacher' => $this->formTeacherClassroom($term)?->getId() === $classroom->getId(),
            'schoolDayCount' => count($schoolDays),
            'registersTaken' => $taken,
            'missingRegisters' => max(0, count($schoolDays) - $taken),
            'pupilCount' => count($roster),
            'traitCount' => count($traits),
            'ratedPupils' => $ratedPupils,
            'remarksWritten' => $this->remarksWritten($classroom, $term, $roster),
            'today' => $this->currentSchoolDay($term),
            'released' => $this->release->isReleased($classroom, $term),
            'outstandingSubjects' => $this->release->outstandingSubjects($classroom, $term),
            'fees' => $this->feeSnapshot($roster, $term),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Fees — what the class has paid, so the form teacher can see it
    // ──────────────────────────────────────────────────────────────────

    /**
     * The class's fees, pupil by pupil.
     *
     * A form teacher is the person a parent rings first, so "has this family
     * paid?" should not require a trip to the bursar. The list shows billed,
     * paid and balance per pupil — no discounts broken down, no ledger, just the
     * question the teacher is actually asked.
     */
    #[Route('/fees', name: 'app_tenant_my_class_fees', methods: ['GET'])]
    public function fees(Request $request): Response
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);
        $roster = $this->roster($classroom, $term);

        return $this->render('tenant/my_class/fees.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'fees' => $this->feeSnapshot($roster, $term),
            'learner' => $this->wording,
            // A form teacher needs to know WHO owes, not how much. Large figures
            // on a class list become a talking point about other families; the
            // numbers stay with the bursary.
            'showAmounts' => $this->isGranted('ROLE_BURSAR'),
        ]);
    }

    /**
     * Billed / paid / outstanding for every pupil in the roster.
     *
     * Reads the pupil's academic invoice for the active term; a pupil with no
     * invoice is "not billed" rather than "owes nothing", which are very
     * different things to a form teacher chasing a parent.
     *
     * @param array<int, array{student: Student, from: \DateTimeImmutable}> $roster
     * @return array{billed: float, collected: float, outstanding: float, cleared: int, owing: int, notBilled: int, rows: array<int, array<string, mixed>>}
     */
    private function feeSnapshot(array $roster, ?Term $term): array
    {
        $empty = ['billed' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'cleared' => 0, 'owing' => 0, 'notBilled' => 0, 'rows' => []];

        if ($roster === [] || $term === null) {
            return $empty;
        }

        $invoices = $this->em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->andWhere('i.student IN (:ids)')
            ->andWhere('i.type = :type')
            ->andWhere('i.term = :term')
            ->setParameter('ids', array_keys($roster))
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->setParameter('term', $term)
            ->orderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($invoices as $invoice) {
            $studentId = (int) $invoice->getStudent()?->getId();
            if ($studentId > 0 && !isset($latest[$studentId])) {
                $latest[$studentId] = $invoice;
            }
        }

        $rows = [];
        $billed = $collected = $outstanding = 0.0;
        $cleared = $owing = $notBilled = 0;

        foreach ($roster as $studentId => $entry) {
            $invoice = $latest[$studentId] ?? null;

            if ($invoice === null) {
                $notBilled++;
                $rows[$studentId] = [
                    'student' => $entry['student'],
                    'invoice' => null,
                    'billed' => 0.0,
                    'paid' => 0.0,
                    'balance' => 0.0,
                    'status' => 'NOT BILLED',
                ];
                continue;
            }

            $total = (float) $invoice->getTotalAmount();
            $paid = (float) $invoice->getPaidAmount();
            $balance = max(0.0, $total - $paid);

            $billed += $total;
            $collected += $paid;
            $outstanding += $balance;

            if ($balance <= 0.01) {
                $cleared++;
            } else {
                $owing++;
            }

            $rows[$studentId] = [
                'student' => $entry['student'],
                'invoice' => $invoice,
                'billed' => $total,
                'paid' => $paid,
                'balance' => $balance,
                'status' => $invoice->getStatus(),
            ];
        }

        return [
            'billed' => $billed,
            'collected' => $collected,
            'outstanding' => $outstanding,
            'cleared' => $cleared,
            'owing' => $owing,
            'notBilled' => $notBilled,
            'rows' => $rows,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Attendance
    // ──────────────────────────────────────────────────────────────────

    #[Route('/attendance', name: 'app_tenant_my_class_attendance', methods: ['GET'])]
    public function attendance(Request $request): Response
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);

        $registerDates = $this->registerDates($classroom, $term);
        $days = [];
        foreach ($this->calendar->schoolDays($term) as $key => $day) {
            $days[] = [
                'date' => $day,
                'key' => $key,
                'taken' => isset($registerDates[$key]),
                'future' => $day > new \DateTimeImmutable('today'),
            ];
        }
        usort($days, static fn (array $a, array $b) => $b['key'] <=> $a['key']);

        return $this->render('tenant/my_class/attendance.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'days' => $days,
            'today' => $this->currentSchoolDay($term),
            'released' => $this->release->isReleased($classroom, $term),
        ]);
    }

    #[Route('/attendance/{date}', name: 'app_tenant_my_class_register', methods: ['GET', 'POST'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function register(string $date, Request $request): Response|JsonResponse
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if (!$day || $day->format('Y-m-d') !== $date) {
            throw $this->createNotFoundException('That is not a date.');
        }

        $roster = $this->roster($classroom, $term);
        $register = $this->findRegister($classroom, $term, $day);
        $locked = $this->release->isReleased($classroom, $term);

        if ($request->isMethod('POST')) {
            return $this->saveRegister($request, $classroom, $term, $day, $roster, $locked);
        }

        $existing = [];
        if ($register) {
            foreach ($register->getEntries() as $entry) {
                $existing[(int) $entry->getStudent()->getId()] = [
                    'status' => $entry->getStatus(),
                    'note' => $entry->getNote(),
                ];
            }
        }

        return $this->render('tenant/my_class/register.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'date' => $day,
            'roster' => $roster,
            'existing' => $existing,
            'isSchoolDay' => $this->calendar->isSchoolDay($term, $day),
            'isFuture' => $day > new \DateTimeImmutable('today'),
            'locked' => $locked,
            'register' => $register,
            'statuses' => AttendanceEntry::STATUSES,
            'csrf' => $this->csrf->getToken('attendance_save_' . $classroom->getId() . '_' . $date)->getValue(),
        ]);
    }

    /** @param array<int, array{student: Student, from: \DateTimeImmutable}> $roster */
    private function saveRegister(
        Request $request,
        Classroom $classroom,
        Term $term,
        \DateTimeImmutable $day,
        array $roster,
        bool $locked,
    ): JsonResponse {
        if ($locked) {
            return $this->json(['success' => false, 'error' => 'This term is released. An admin must reopen it.'], 409);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Malformed request.'], 400);
        }

        if (!$this->isCsrfTokenValid('attendance_save_' . $classroom->getId() . '_' . $day->format('Y-m-d'), (string) ($payload['_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'That form expired. Reload the page.'], 400);
        }

        if ($day > new \DateTimeImmutable('today')) {
            return $this->json(['success' => false, 'error' => 'You cannot mark a day that has not happened.'], 400);
        }

        if (!$this->calendar->isSchoolDay($term, $day)) {
            return $this->json(['success' => false, 'error' => 'That date is not a school day (weekend or holiday).'], 400);
        }

        $staff = $this->staff();
        $actor = $this->getUser()?->getUserIdentifier() ?? 'staff';

        $register = $this->findRegister($classroom, $term, $day);
        if (!$register) {
            $register = (new AttendanceRegister())
                ->setClassroom($classroom)
                ->setTerm($term)
                ->setDate($day)
                ->setSlot(self::SLOT);
            $this->em->persist($register);
        }

        $register->setTakenBy($staff)->setTakenAt(new \DateTimeImmutable());

        $byStudent = [];
        foreach ($register->getEntries() as $entry) {
            $byStudent[(int) $entry->getStudent()->getId()] = $entry;
        }

        foreach ($payload['entries'] ?? [] as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);
            $status = (string) ($row['status'] ?? '');

            if (!isset($roster[$studentId]) || !isset(AttendanceEntry::STATUSES[$status])) {
                continue;
            }

            $note = trim((string) ($row['note'] ?? '')) ?: null;
            $entry = $byStudent[$studentId] ?? null;

            if (!$entry) {
                $entry = (new AttendanceEntry())
                    ->setStudent($roster[$studentId]['student'])
                    ->setStatus($status)
                    ->setNote($note);
                $entry->touch($actor);
                $register->addEntry($entry);
                $this->em->persist($entry);
                continue;
            }

            if ($entry->getStatus() !== $status) {
                $this->em->persist(new AttendanceChange($entry, $entry->getStatus(), $status, $actor));
                $entry->setStatus($status)->touch($actor);
            }
            if ($entry->getNote() !== $note) {
                $entry->setNote($note)->touch($actor);
            }
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'register_id' => $register->getId(),
            'summary' => $this->tally($register),
        ]);
    }

    #[Route('/attendance-summary', name: 'app_tenant_my_class_attendance_summary', methods: ['GET'])]
    public function attendanceSummary(Request $request): Response
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);
        $roster = $this->roster($classroom, $term);

        return $this->render('tenant/my_class/attendance_summary.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'summary' => $this->attendanceSummary->forClassroomTerm($classroom, $term, $roster),
            'pupilCount' => count($roster),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Behaviour
    // ──────────────────────────────────────────────────────────────────

    #[Route('/behaviour', name: 'app_tenant_my_class_behaviour', methods: ['GET'])]
    public function behaviour(Request $request): Response
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);
        $this->traitSeeder->ensureSeeded();

        $roster = $this->roster($classroom, $term);
        $traits = $this->activeTraits();

        $rows = $roster === [] ? [] : $this->em->getRepository(BehaviourRating::class)->createQueryBuilder('r')
            ->select('IDENTITY(r.student) AS sid, IDENTITY(r.trait) AS tid, r.score')
            ->andWhere('r.term = :term')
            ->andWhere('r.student IN (:students)')
            ->setParameter('term', $term)
            ->setParameter('students', array_map(static fn ($r) => $r['student'], $roster))
            ->getQuery()
            ->getArrayResult();

        $ratings = [];
        foreach ($rows as $row) {
            $ratings[(int) $row['sid']][(int) $row['tid']] = (int) $row['score'];
        }

        $total = count($traits);

        // One pupil at a time: 41 pupils × 18 traits is 738 pickers, which is both a
        // heavy page and an unusable one. The list shows progress; the panel rates.
        $pupils = [];
        foreach ($roster as $studentId => $info) {
            $done = count($ratings[$studentId] ?? []);
            $pupils[] = [
                'id' => $studentId,
                'name' => trim(($info['student']->getFirstName() ?? '') . ' ' . ($info['student']->getLastName() ?? '')),
                'rated' => $done,
                'total' => $total,
                'complete' => $total > 0 && $done >= $total,
            ];
        }

        // `getInt()` 400s on an empty value; a cleared picker must not break the page.
        $selectedId = (int) $request->query->get('student', 0);
        if ($selectedId <= 0 || !isset($roster[$selectedId])) {
            // Default to the first pupil who still has work outstanding.
            $firstIncomplete = null;
            foreach ($pupils as $pupil) {
                if (!$pupil['complete']) {
                    $firstIncomplete = $pupil['id'];
                    break;
                }
            }
            $selectedId = $firstIncomplete ?? (int) ($pupils[0]['id'] ?? 0);
        }

        $affective = array_values(array_filter($traits, static fn (BehaviourTrait $t) => $t->getDomain() === BehaviourTrait::AFFECTIVE));
        $psychomotor = array_values(array_filter($traits, static fn (BehaviourTrait $t) => $t->getDomain() === BehaviourTrait::PSYCHOMOTOR));

        return $this->render('tenant/my_class/behaviour.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'pupils' => $pupils,
            'selectedId' => $selectedId,
            'selectedStudent' => $roster[$selectedId]['student'] ?? null,
            'selectedRatings' => $ratings[$selectedId] ?? [],
            'affective' => $affective,
            'psychomotor' => $psychomotor,
            'labels' => BehaviourRating::LABELS,
            'locked' => $this->release->isReleased($classroom, $term),
            'ratedPupils' => $this->countRated($roster, $traits, $ratings),
            'pupilCount' => count($roster),
            'csrf' => $this->csrf->getToken('behaviour_save')->getValue(),
        ]);
    }

    #[Route('/behaviour/{student}/{trait}', name: 'app_tenant_my_class_behaviour_save', methods: ['POST'], requirements: ['student' => '\d+', 'trait' => '\d+'])]
    public function saveBehaviour(Student $student, BehaviourTrait $trait, Request $request): JsonResponse
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);

        if ($this->release->isReleased($classroom, $term)) {
            return $this->json(['success' => false, 'error' => 'This term is released. An admin must reopen it.'], 409);
        }

        if (!array_key_exists((int) $student->getId(), $this->roster($classroom, $term))) {
            return $this->json(['success' => false, 'error' => 'That ' . $this->wording . ' is not in this class.'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!$this->isCsrfTokenValid('behaviour_save', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'That form expired. Reload the page.'], 400);
        }

        $score = (int) ($payload['score'] ?? 0);
        if ($score < BehaviourRating::MIN || $score > BehaviourRating::MAX) {
            return $this->json(['success' => false, 'error' => 'Score must be 1 to 5.'], 400);
        }

        $rating = $this->em->getRepository(BehaviourRating::class)->findOneBy([
            'student' => $student,
            'term' => $term,
            'trait' => $trait,
        ]);

        $actor = $this->getUser()?->getUserIdentifier() ?? 'staff';

        if (!$rating) {
            $rating = (new BehaviourRating())
                ->setStudent($student)
                ->setTerm($term)
                ->setTrait($trait)
                ->setRatedBy($this->staff());
            $this->em->persist($rating);
        }

        $rating->setScore($score)->touch($actor);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'score' => $rating->getScore(),
            'label' => $rating->label(),
        ]);
    }

    /**
     * Fill every active trait for one pupil with the same score.
     *
     * Rating 41 pupils × 18 traits one cell at a time is 738 taps, so the teacher
     * sets a sensible baseline and adjusts the handful who stand out.
     */
    #[Route('/behaviour/{student}/all', name: 'app_tenant_my_class_behaviour_save_all', methods: ['POST'], requirements: ['student' => '\d+'])]
    public function fillBehaviour(Student $student, Request $request): JsonResponse
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);

        if ($this->release->isReleased($classroom, $term)) {
            return $this->json(['success' => false, 'error' => 'This term is released. An admin must reopen it.'], 409);
        }

        if (!array_key_exists((int) $student->getId(), $this->roster($classroom, $term))) {
            return $this->json(['success' => false, 'error' => 'That ' . $this->wording . ' is not in this class.'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!$this->isCsrfTokenValid('behaviour_save', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'That form expired. Reload the page.'], 400);
        }

        $score = (int) ($payload['score'] ?? 0);
        if ($score < BehaviourRating::MIN || $score > BehaviourRating::MAX) {
            return $this->json(['success' => false, 'error' => 'Score must be 1 to 5.'], 400);
        }

        $actor = $this->getUser()?->getUserIdentifier() ?? 'staff';
        $traits = $this->activeTraits();
        $this->traitSeeder->ensureSeeded();

        $existing = [];
        foreach ($this->em->getRepository(BehaviourRating::class)->findBy([
            'student' => $student,
            'term' => $term,
        ]) as $rating) {
            $existing[(int) $rating->getTrait()->getId()] = $rating;
        }

        foreach ($traits as $trait) {
            $rating = $existing[(int) $trait->getId()] ?? null;
            if (!$rating) {
                $rating = (new BehaviourRating())
                    ->setStudent($student)
                    ->setTerm($term)
                    ->setTrait($trait)
                    ->setRatedBy($this->staff());
                $this->em->persist($rating);
            }
            $rating->setScore($score)->touch($actor);
        }

        $this->em->flush();

        return $this->json(['success' => true, 'score' => $score, 'count' => count($traits)]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Remarks
    // ──────────────────────────────────────────────────────────────────

    /**
     * The form teacher's remark: one paragraph per pupil, keyed to the term.
     *
     * Suggestion first, editing second. A blank box for 41 pupils is the reason
     * report systems are abandoned (RESULT-CARD-DESIGN.md §11 decision 9).
     */
    #[Route('/remarks', name: 'app_tenant_my_class_remarks', methods: ['GET'])]
    public function remarks(Request $request): Response
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);
        $roster = $this->roster($classroom, $term);

        $existing = [];
        if ($roster !== []) {
            foreach ($this->em->getRepository(StudentTermRemark::class)->findBy([
                'student' => array_map(static fn ($r) => $r['student'], $roster),
                'term' => $term,
            ]) as $remark) {
                $existing[(int) $remark->getStudent()->getId()] = $remark;
            }
        }

        $pupils = [];
        foreach ($roster as $studentId => $info) {
            $pupils[] = [
                'id' => $studentId,
                'name' => trim(($info['student']->getFirstName() ?? '') . ' ' . ($info['student']->getLastName() ?? '')),
                'written' => isset($existing[$studentId]) && $existing[$studentId]->isWritten(),
            ];
        }

        $selectedId = (int) $request->query->get('student', 0);
        if ($selectedId <= 0 || !isset($roster[$selectedId])) {
            $firstMissing = null;
            foreach ($pupils as $pupil) {
                if (!$pupil['written']) {
                    $firstMissing = $pupil['id'];
                    break;
                }
            }
            $selectedId = $firstMissing ?? (int) ($pupils[0]['id'] ?? 0);
        }

        $selectedStudent = $roster[$selectedId]['student'] ?? null;
        $remark = $existing[$selectedId] ?? null;

        $ids = array_keys($roster);
        $index = array_search($selectedId, $ids, true);
        $nextId = $ids === []
            ? $selectedId
            : $ids[($index === false ? 0 : ((int) $index + 1) % count($ids))];

        $suggestion = null;
        if ($selectedStudent) {
            $sheet = $this->sheetBuilder->build($selectedStudent, $term);
            $suggestion = $this->suggestions->for(
                $selectedStudent->getFirstName(),
                $sheet['summary']['percentage'] ?? null
            );
        }

        return $this->render('tenant/my_class/remarks.html.twig', [
            'term' => $term,
            'classroom' => $classroom,
            'classes' => $this->availableClasses($term),
            'pupils' => $pupils,
            'selectedId' => $selectedId,
            'nextId' => $nextId,
            'selectedStudent' => $selectedStudent,
            'remark' => $remark,
            'suggestion' => $suggestion,
            'maxLength' => self::REMARK_MAX,
            'written' => count(array_filter($pupils, static fn (array $p) => $p['written'])),
            'pupilCount' => count($pupils),
            'locked' => $this->release->isReleased($classroom, $term),
            'csrf' => $this->csrf->getToken('remark_save')->getValue(),
        ]);
    }

    #[Route('/remarks/{student}', name: 'app_tenant_my_class_remark_save', methods: ['POST'], requirements: ['student' => '\d+'])]
    public function saveRemark(Student $student, Request $request): JsonResponse
    {
        $term = $this->activeTerm();
        $classroom = $this->resolveClassroom($request, $term);

        if ($this->release->isReleased($classroom, $term)) {
            return $this->json(['success' => false, 'error' => 'This term is released. An admin must reopen it.'], 409);
        }

        if (!array_key_exists((int) $student->getId(), $this->roster($classroom, $term))) {
            return $this->json(['success' => false, 'error' => 'That ' . $this->wording . ' is not in this class.'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!$this->isCsrfTokenValid('remark_save', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'That form expired. Reload the page.'], 400);
        }

        $text = trim((string) ($payload['text'] ?? ''));
        if (mb_strlen($text) > self::REMARK_MAX) {
            return $this->json(['success' => false, 'error' => 'Keep the remark under ' . self::REMARK_MAX . ' characters.'], 400);
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

        $remark->setText($text)->touch(
            $this->getUser()?->getUserIdentifier() ?? 'staff',
            $this->staff()
        );
        $this->em->flush();

        return $this->json([
            'success' => true,
            'written' => $remark->isWritten(),
            'remaining' => self::REMARK_MAX - mb_strlen($text),
        ]);
    }

    /** @param array<int, array{student: Student, from: \DateTimeImmutable}> $roster */
    private function remarksWritten(Classroom $classroom, Term $term, array $roster): int
    {
        if ($roster === []) {
            return 0;
        }

        return (int) $this->em->getRepository(StudentTermRemark::class)
            ->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.term = :term')
            ->andWhere('r.student IN (:students)')
            ->andWhere('r.text IS NOT NULL')
            ->andWhere("r.text <> ''")
            ->setParameter('term', $term)
            ->setParameter('students', array_map(static fn ($row) => $row['student'], $roster))
            ->getQuery()
            ->getSingleScalarResult();
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

        if (!$term->getStartDate() || !$term->getEndDate()) {
            throw $this->createNotFoundException('The active term has no start or end date, so there is no school calendar.');
        }

        return $term;
    }

    /** The class this user is form master of for the active term, if any. */
    private function formTeacherClassroom(Term $term): ?Classroom
    {
        $staff = $this->getUser()?->getStaff();
        if (!$staff) {
            return null;
        }

        $assignment = $this->em->getRepository(FormTeacher::class)->findOneBy([
            'staff' => $staff,
            'term' => $term,
        ]);

        return $assignment?->getClassroom();
    }

    private function resolveClassroom(Request $request, Term $term): Classroom
    {
        $own = $this->formTeacherClassroom($term);

        // An admin (or a teacher looking at another class with permission) may pass
        // ?classroom=; a form master without that parameter always gets their own.
        // Empty means "not given" — `getInt()` would answer that with a 400.
        $requested = (int) $request->query->get('classroom', 0);
        if ($requested > 0 && ($own === null || $this->isGranted('ROLE_ADMIN'))) {
            $classroom = $this->em->getRepository(Classroom::class)->find($requested);
            if (!$classroom) {
                throw $this->createNotFoundException('No such class.');
            }

            return $classroom;
        }

        if ($own) {
            return $own;
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            $classroom = $this->em->getRepository(Classroom::class)->findOneBy([], ['name' => 'ASC']);
            if ($classroom) {
                return $classroom;
            }
        }

        throw $this->createAccessDeniedException(
            'You are not a form master for the active term, so you have no class to look after.'
        );
    }

    /** @return list<Classroom> */
    private function availableClasses(Term $term): array
    {
        $own = $this->formTeacherClassroom($term);
        if ($own && !$this->isGranted('ROLE_ADMIN')) {
            return [$own];
        }

        return $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']);
    }

    /**
     * Who is in this class, and from when.
     *
     * Enrolment is authoritative for the session; `currentClassroom` is the fallback
     * for a class that has not been promoted into enrolments yet.
     *
     * @return array<int, array{student: Student, from: \DateTimeImmutable}>
     */
    private function roster(Classroom $classroom, Term $term): array
    {
        $defaultFrom = $term->getStartDate() ?? new \DateTimeImmutable();

        $session = $term->getSession();
        $enrollments = $session
            ? $this->em->getRepository(Enrollment::class)->findBy([
                'classroom' => $classroom,
                'session' => $session,
            ])
            : [];

        $roster = [];
        foreach ($enrollments as $enrollment) {
            $student = $enrollment->getStudent();
            if (!$student) {
                continue;
            }
            $roster[(int) $student->getId()] = [
                'student' => $student,
                'from' => $enrollment->getEnrolledAt() ?? $defaultFrom,
            ];
        }

        if ($roster === []) {
            foreach ($this->em->getRepository(Student::class)->findBy(['currentClassroom' => $classroom]) as $student) {
                $roster[(int) $student->getId()] = ['student' => $student, 'from' => $defaultFrom];
            }
        }

        uasort($roster, static function (array $a, array $b) {
            return strcasecmp(
                ($a['student']->getLastName() ?? '') . ' ' . ($a['student']->getFirstName() ?? ''),
                ($b['student']->getLastName() ?? '') . ' ' . ($b['student']->getFirstName() ?? '')
            );
        });

        return $roster;
    }

    /** @return array<string, AttendanceRegister> keyed Y-m-d */
    private function registerDates(Classroom $classroom, Term $term): array
    {
        $registers = $this->em->getRepository(AttendanceRegister::class)->findBy([
            'classroom' => $classroom,
            'term' => $term,
            'slot' => self::SLOT,
        ]);

        $out = [];
        foreach ($registers as $register) {
            $out[$register->getDate()->format('Y-m-d')] = $register;
        }

        return $out;
    }

    private function findRegister(Classroom $classroom, Term $term, \DateTimeImmutable $day): ?AttendanceRegister
    {
        return $this->em->getRepository(AttendanceRegister::class)->findOneBy([
            'classroom' => $classroom,
            'term' => $term,
            'date' => $day,
            'slot' => self::SLOT,
        ]);
    }

    /** The most recent school day that is today or before — what "take attendance" opens. */
    private function currentSchoolDay(Term $term): ?\DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today');
        $best = null;
        foreach ($this->calendar->schoolDays($term) as $day) {
            if ($day <= $today) {
                $best = $day;
            }
        }

        return $best;
    }

    /** @return list<BehaviourTrait> */
    private function activeTraits(): array
    {
        return $this->em->getRepository(BehaviourTrait::class)->findBy(
            ['isActive' => true],
            ['domain' => 'ASC', 'sortOrder' => 'ASC', 'label' => 'ASC']
        );
    }

    /** @param array<int, array{student: Student, from: \DateTimeImmutable}> $roster */
    private function ratedPupilCount(Classroom $classroom, Term $term, array $roster, array $traits): int
    {
        if ($roster === [] || $traits === []) {
            return 0;
        }

        $rows = $this->em->getRepository(BehaviourRating::class)->createQueryBuilder('r')
            ->select('IDENTITY(r.student) AS sid, COUNT(r.id) AS n')
            ->andWhere('r.term = :term')
            ->andWhere('r.student IN (:students)')
            ->setParameter('term', $term)
            ->setParameter('students', array_map(static fn ($r) => $r['student'], $roster))
            ->groupBy('r.student')
            ->getQuery()
            ->getArrayResult();

        $needed = count($traits);
        $complete = 0;
        foreach ($rows as $row) {
            if ((int) $row['n'] >= $needed) {
                $complete++;
            }
        }

        return $complete;
    }

    private function countRated(array $roster, array $traits, array $ratings): int
    {
        $needed = count($traits);
        if ($needed === 0) {
            return 0;
        }

        $complete = 0;
        foreach ($roster as $studentId => $_) {
            if (count($ratings[$studentId] ?? []) >= $needed) {
                $complete++;
            }
        }

        return $complete;
    }

    private function staff(): ?\App\Entity\Tenant\Staff
    {
        return $this->getUser()?->getStaff();
    }

    /** @return array<string, int> */
    private function tally(AttendanceRegister $register): array
    {
        $out = ['P' => 0, 'A' => 0, 'L' => 0, 'E' => 0, 'total' => 0];
        foreach ($register->getEntries() as $entry) {
            $out[$entry->getStatus()] = ($out[$entry->getStatus()] ?? 0) + 1;
            $out['total']++;
        }

        return $out;
    }
}
