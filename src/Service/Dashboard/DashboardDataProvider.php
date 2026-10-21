<?php

namespace App\Service\Dashboard;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\AttendanceRegister;
use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Product;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\ResultComponentScore;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentTermRemark;
use App\Entity\Tenant\Subject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\YearGroupMaster;
use App\Service\SchoolLife\SchoolCalendar;
use App\Service\SchoolLife\TermRelease;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The numbers each dashboard panel needs, and nothing else.
 *
 * Every method here is gated by the controller on the persona's capability, so a
 * teacher never triggers a fees query and a store keeper never triggers a results
 * query. That is not just tidiness: a school's finance is not a teacher's
 * business, and a dashboard that loads it "to be safe" is one template mistake
 * away from showing it.
 */
final class DashboardDataProvider
{
    private const LOW_STOCK_THRESHOLD = 5;

    public function __construct(
        private EntityManagerInterface $em,
        private SchoolCalendar $calendar,
        private TermRelease $release,
    ) {
    }

    public function activeTerm(): ?Term
    {
        return $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);
    }

    public function activeSession(): ?Session
    {
        return $this->em->getRepository(Session::class)->findOneBy(['isActive' => true]);
    }

    /**
     * The three "special academic roles" are assignments, not login roles.
     *
     * @return array{hasFormClass: bool, isDepartmentHead: bool, isYearGroupMaster: bool}
     */
    public function assignmentFlags(?Staff $staff, ?Term $term): array
    {
        if (!$staff) {
            return ['hasFormClass' => false, 'isDepartmentHead' => false, 'isYearGroupMaster' => false];
        }

        $session = $term?->getSession();

        return [
            'hasFormClass' => $term !== null
                && $this->em->getRepository(FormTeacher::class)->findOneBy(['staff' => $staff, 'term' => $term]) !== null,
            'isDepartmentHead' => $session !== null
                && $this->em->getRepository(DepartmentHead::class)->findOneBy(['staff' => $staff, 'session' => $session]) !== null,
            'isYearGroupMaster' => $session !== null
                && $this->em->getRepository(YearGroupMaster::class)->findOneBy(['staff' => $staff, 'session' => $session]) !== null,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Whole school — administrator / super admin
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function overview(?Term $term): array
    {
        $billed = $this->money(
            'SELECT SUM(i.totalAmount) FROM ' . Invoice::class . ' i WHERE i.type = :academic',
            ['academic' => Invoice::TYPE_ACADEMIC]
        );
        $paid = $this->money(
            'SELECT SUM(i.paidAmount) FROM ' . Invoice::class . ' i WHERE i.type = :academic',
            ['academic' => Invoice::TYPE_ACADEMIC]
        );

        $studentCount = (int) $this->em->getRepository(Student::class)->count([]);
        $staffCount = (int) $this->em->getRepository(Staff::class)->count([]);
        $classCount = (int) $this->em->getRepository(Classroom::class)->count([]);
        $subjectCount = (int) $this->em->getRepository(Subject::class)->count([]);

        $unpaidInvoices = (int) $this->em->getRepository(Invoice::class)->count([
            'type' => Invoice::TYPE_ACADEMIC,
            'status' => Invoice::STATUS_UNPAID,
        ]);
        $partialInvoices = (int) $this->em->getRepository(Invoice::class)->count([
            'type' => Invoice::TYPE_ACADEMIC,
            'status' => Invoice::STATUS_PARTIAL,
        ]);

        $pendingApplications = (int) $this->em->getRepository(Application::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.status IN (:open)')
            ->setParameter('open', [
                Application::STATUS_SUBMITTED,
                Application::STATUS_UNDER_REVIEW,
                Application::STATUS_NEEDS_INFO,
                Application::STATUS_WAITLISTED,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        [$resultSubjects, $resultApproved] = $this->resultReadiness($term);

        return [
            'studentCount' => $studentCount,
            'staffCount' => $staffCount,
            'classCount' => $classCount,
            'subjectCount' => $subjectCount,
            'billed' => $billed,
            'paid' => $paid,
            'outstanding' => max(0, $billed - $paid),
            'collectedToday' => $this->collectedToday(),
            'debtorCount' => $unpaidInvoices + $partialInvoices,
            'pendingApplications' => $pendingApplications,
            'resultSubjects' => $resultSubjects,
            'resultApproved' => $resultApproved,
            'resultPercent' => $resultSubjects > 0 ? (int) round($resultApproved / $resultSubjects * 100) : null,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Finance — bursar, administrator, super admin
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function finance(?Term $term): array
    {
        $billed = $this->money(
            'SELECT SUM(i.totalAmount) FROM ' . Invoice::class . ' i WHERE i.type = :academic',
            ['academic' => Invoice::TYPE_ACADEMIC]
        );
        $paid = $this->money(
            'SELECT SUM(i.paidAmount) FROM ' . Invoice::class . ' i WHERE i.type = :academic',
            ['academic' => Invoice::TYPE_ACADEMIC]
        );

        $unpaidInvoices = (int) $this->em->getRepository(Invoice::class)->count([
            'type' => Invoice::TYPE_ACADEMIC,
            'status' => Invoice::STATUS_UNPAID,
        ]);
        $partialInvoices = (int) $this->em->getRepository(Invoice::class)->count([
            'type' => Invoice::TYPE_ACADEMIC,
            'status' => Invoice::STATUS_PARTIAL,
        ]);

        // The list is capped; the count above it is the truth. A school with
        // 1,200 debtors must not read "Across 5 students".
        $debtors = $this->em->getRepository(Invoice::class)->findBy(
            ['type' => Invoice::TYPE_ACADEMIC, 'status' => Invoice::STATUS_UNPAID],
            ['totalAmount' => 'DESC'],
            5
        );

        $recentPayments = $this->em->getRepository(Payment::class)->findBy(
            ['status' => Payment::STATUS_CONFIRMED],
            ['createdAt' => 'DESC'],
            6
        );

        $collectedThisTerm = 0.0;
        if ($term) {
            $collectedThisTerm = $this->money(
                'SELECT SUM(p.amount) FROM ' . Payment::class . ' p JOIN p.invoice i'
                . ' WHERE i.term = :term AND p.status = :confirmed',
                ['term' => $term, 'confirmed' => Payment::STATUS_CONFIRMED]
            );
        }

        // Money that has been claimed but not yet checked against the bank. This
        // is the bursar's first job of the day, so it is the first card.
        $pendingVerifications = (int) $this->em->getRepository(Payment::class)->count([
            'status' => Payment::STATUS_PENDING,
        ]);
        $pendingVerificationTotal = $this->money(
            'SELECT SUM(p.amount) FROM ' . Payment::class . ' p WHERE p.status = :pending',
            ['pending' => Payment::STATUS_PENDING]
        );

        return [
            'billed' => $billed,
            'paid' => $paid,
            'outstanding' => max(0, $billed - $paid),
            'collectedToday' => $this->collectedToday(),
            'collectedThisTerm' => $collectedThisTerm,
            'debtorCount' => $unpaidInvoices + $partialInvoices,
            'unpaidInvoices' => $unpaidInvoices,
            'partialInvoices' => $partialInvoices,
            'debtors' => $debtors,
            'recentPayments' => $recentPayments,
            'pendingVerifications' => $pendingVerifications,
            'pendingVerificationTotal' => $pendingVerificationTotal,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Admissions — admissions officer, administrator, super admin
    // ──────────────────────────────────────────────────────────────────

    /**
     * The admissions desk's day: what is waiting for a decision, and the five
     * most recent files. No money and no results — this seat admits children.
     *
     * @return array{queue: int, submitted: int, underReview: int, needsInfo: int, waitlisted: int, recent: list<Application>}
     */
    public function admissions(): array
    {
        $counts = ['submitted' => 0, 'under_review' => 0, 'needs_info' => 0, 'waitlisted' => 0];

        $rows = $this->em->getRepository(Application::class)->createQueryBuilder('a')
            ->select('a.status AS status', 'COUNT(a.id) AS total')
            ->andWhere('a.status IN (:open)')
            ->setParameter('open', array_keys($counts))
            ->groupBy('a.status')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        $recent = $this->em->getRepository(Application::class)->createQueryBuilder('a')
            ->leftJoin('a.requestedYearGroup', 'yg')->addSelect('yg')
            ->andWhere('a.status IN (:open)')
            ->setParameter('open', array_keys($counts))
            ->orderBy('a.submittedAt', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return [
            'queue' => array_sum($counts),
            'submitted' => $counts['submitted'],
            'underReview' => $counts['under_review'],
            'needsInfo' => $counts['needs_info'],
            'waitlisted' => $counts['waitlisted'],
            'recent' => $recent,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Store — store keeper, administrator, super admin
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function store(): array
    {
        $products = $this->em->getRepository(Product::class)->count([]);

        $lowStock = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->where('p.stockQuantity < :threshold')
            ->setParameter('threshold', self::LOW_STOCK_THRESHOLD)
            ->orderBy('p.stockQuantity', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $stockValue = $this->money(
            'SELECT SUM(p.stockQuantity * p.unitPrice) FROM ' . Product::class . ' p'
        );

        $today = new \DateTimeImmutable('today 00:00:00');
        $salesToday = $this->money(
            'SELECT SUM(i.totalAmount) FROM ' . Invoice::class . ' i'
            . ' WHERE i.type = :store AND i.createdAt >= :today',
            ['store' => Invoice::TYPE_STORE, 'today' => $today]
        );
        $salesCountToday = (int) $this->em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.type = :store')
            ->andWhere('i.createdAt >= :today')
            ->setParameter('store', Invoice::TYPE_STORE)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'productCount' => $products,
            'lowStock' => $lowStock,
            'lowStockCount' => count($lowStock),
            'stockValue' => $stockValue,
            'salesToday' => $salesToday,
            'salesCountToday' => $salesCountToday,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Teaching — subject teacher, form teacher, HOD, year group master
    // ──────────────────────────────────────────────────────────────────

    /**
     * One row per class/subject this teacher holds for the active term.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, submitted: int, waiting: int, returned: int, approved: int}
     */
    public function teacherWork(?Staff $staff, ?Term $term): array
    {
        $empty = ['rows' => [], 'total' => 0, 'submitted' => 0, 'waiting' => 0, 'returned' => 0, 'approved' => 0];
        if (!$staff || !$term) {
            return $empty;
        }

        $assignments = $this->em->getRepository(SubjectTeacher::class)->findBy(
            ['staff' => $staff, 'term' => $term],
            ['id' => 'ASC']
        );

        $session = $term->getSession();
        $rows = [];
        $counts = ['submitted' => 0, 'waiting' => 0, 'returned' => 0, 'approved' => 0];

        foreach ($assignments as $assignment) {
            $classSubject = $session ? $this->em->getRepository(ClassSubject::class)->findOneBy([
                'subject' => $assignment->getSubject(),
                'classroom' => $assignment->getClassroom(),
                'session' => $session,
            ]) : null;

            $approval = $classSubject ? $this->em->getRepository(ResultApproval::class)->findOneBy([
                'classSubject' => $classSubject,
                'term' => $term,
            ]) : null;

            $submittedScores = $classSubject ? (int) $this->em->getRepository(ResultComponentScore::class)->count([
                'classSubject' => $classSubject,
                'term' => $term,
                'status' => ResultComponentScore::STATUS_SUBMITTED,
            ]) : 0;

            $status = 'not_started';
            if ($approval && (
                $approval->getHodStatus() === ResultApproval::STATUS_REJECTED
                || $approval->getFormTeacherStatus() === ResultApproval::STATUS_REJECTED
            )) {
                $status = 'returned';
            } elseif ($approval && $approval->getYearGroupStatus() === ResultApproval::STATUS_APPROVED) {
                $status = 'approved';
            } elseif ($submittedScores > 0) {
                $status = 'submitted';
            }

            if ($status === 'returned') {
                $counts['returned']++;
            } elseif ($status === 'approved') {
                $counts['approved']++;
            } elseif ($status === 'submitted') {
                $counts['submitted']++;
            } else {
                $counts['waiting']++;
            }

            $rows[] = [
                'assignment' => $assignment,
                'subject' => $assignment->getSubject(),
                'classroom' => $assignment->getClassroom(),
                'status' => $status,
                'submittedScores' => $submittedScores,
                'reason' => $approval?->getHodReason(),
            ];
        }

        return [
            'rows' => $rows,
            'total' => count($rows),
            'submitted' => $counts['submitted'],
            'waiting' => $counts['waiting'],
            'returned' => $counts['returned'],
            'approved' => $counts['approved'],
        ];
    }

    /**
     * The form class this teacher looks after, with the term's paperwork state.
     *
     * @return array<string, mixed>|null
     */
    public function formClass(?Staff $staff, ?Term $term): ?array
    {
        if (!$staff || !$term) {
            return null;
        }

        $assignment = $this->em->getRepository(FormTeacher::class)->findOneBy([
            'staff' => $staff,
            'term' => $term,
        ]);
        $classroom = $assignment?->getClassroom();
        if (!$classroom) {
            return null;
        }

        $studentIds = $this->rosterIds($classroom, $term);
        $today = new \DateTimeImmutable('today');

        $daysSoFar = $this->calendar->schoolDaysUpTo($term, $today);
        $registersTaken = (int) $this->em->getRepository(AttendanceRegister::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.classroom = :classroom')
            ->andWhere('r.term = :term')
            ->andWhere('r.slot = :slot')
            ->andWhere('r.date <= :today')
            ->setParameter('classroom', $classroom)
            ->setParameter('term', $term)
            ->setParameter('slot', AttendanceRegister::SLOT_FULL)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();

        $remarksWritten = 0;
        if ($studentIds !== []) {
            $remarksWritten = (int) $this->em->getRepository(StudentTermRemark::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->andWhere('r.term = :term')
                ->andWhere('r.student IN (:students)')
                ->andWhere('r.text IS NOT NULL')
                ->andWhere("r.text <> ''")
                ->setParameter('term', $term)
                ->setParameter('students', $studentIds)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return [
            'classroom' => $classroom,
            'pupilCount' => count($studentIds),
            'daysSoFar' => $daysSoFar,
            'registersTaken' => $registersTaken,
            'registersMissing' => max(0, $daysSoFar - $registersTaken),
            'todayRegisterTaken' => $this->em->getRepository(AttendanceRegister::class)->findOneBy([
                'classroom' => $classroom,
                'term' => $term,
                'date' => $today,
                'slot' => AttendanceRegister::SLOT_FULL,
            ]) !== null,
            'remarksWritten' => $remarksWritten,
            'remarksOutstanding' => max(0, count($studentIds) - $remarksWritten),
            'outstandingSubjects' => $this->release->outstandingSubjects($classroom, $term),
            'released' => $this->release->isReleased($classroom, $term),
        ];
    }

    /**
     * Approval queues for the departments this user heads.
     *
     * @return list<array<string, mixed>>
     */
    public function departmentWork(?Staff $staff, ?Term $term): array
    {
        $session = $term?->getSession();
        if (!$staff || !$term || !$session) {
            return [];
        }

        $assignments = $this->em->getRepository(DepartmentHead::class)->findBy(['staff' => $staff, 'session' => $session]);
        $out = [];

        foreach ($assignments as $assignment) {
            $department = $assignment->getDepartment();
            $subjects = $department ? $this->em->getRepository(Subject::class)->findBy(['department' => $department]) : [];
            $classSubjects = $subjects
                ? $this->em->getRepository(ClassSubject::class)->findBy(['subject' => $subjects, 'session' => $session])
                : [];

            $classrooms = [];
            foreach ($classSubjects as $classSubject) {
                if ($classSubject->getClassroom()) {
                    $classrooms[$classSubject->getClassroom()->getId()] = true;
                }
            }

            $out[] = [
                'department' => $department,
                'subjectCount' => count($subjects),
                'classCount' => count($classrooms),
            ] + $this->stageStats($classSubjects, $term, 'hod');
        }

        return $out;
    }

    /**
     * Approval queues for the year groups this user masters.
     *
     * @return list<array<string, mixed>>
     */
    public function yearGroupWork(?Staff $staff, ?Term $term): array
    {
        $session = $term?->getSession();
        if (!$staff || !$term || !$session) {
            return [];
        }

        $assignments = $this->em->getRepository(YearGroupMaster::class)->findBy(['staff' => $staff, 'session' => $session]);
        $out = [];

        foreach ($assignments as $assignment) {
            $yearGroup = $assignment->getYearGroup();
            $classrooms = $yearGroup ? $this->em->getRepository(Classroom::class)->findBy(['yearGroup' => $yearGroup]) : [];
            $classSubjects = $classrooms
                ? $this->em->getRepository(ClassSubject::class)->findBy(['classroom' => $classrooms, 'session' => $session])
                : [];

            $out[] = [
                'yearGroup' => $yearGroup,
                'classCount' => count($classrooms),
            ] + $this->stageStats($classSubjects, $term, 'year_group');
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Counts for one approval stage.
     *
     * `waiting` deliberately counts only sheets that a teacher has actually
     * submitted. A sheet nobody has touched is the teacher's outstanding work,
     * not an approval sitting in the approver's queue — showing it as the latter
     * makes the queue look permanently full and trains people to ignore it.
     *
     * @param list<ClassSubject> $classSubjects
     * @return array{total: int, waiting: int, approved: int, returned: int, submitted: int}
     */
    private function stageStats(array $classSubjects, Term $term, string $stage): array
    {
        $stats = ['total' => count($classSubjects), 'waiting' => 0, 'approved' => 0, 'returned' => 0, 'submitted' => 0];
        if ($classSubjects === []) {
            return $stats;
        }

        $ids = array_values(array_filter(array_map(static fn (ClassSubject $cs) => $cs->getId(), $classSubjects)));

        $approvals = [];
        if ($ids !== []) {
            foreach ($this->em->getRepository(ResultApproval::class)->createQueryBuilder('ra')
                ->andWhere('ra.classSubject IN (:ids)')
                ->andWhere('ra.term = :term')
                ->setParameter('ids', $ids)
                ->setParameter('term', $term)
                ->getQuery()
                ->getResult() as $approval) {
                $approvals[$approval->getClassSubject()->getId()] = $approval;
            }
        }

        $submitted = [];
        if ($ids !== []) {
            foreach ($this->em->getRepository(ResultComponentScore::class)->createQueryBuilder('s')
                ->select('IDENTITY(s.classSubject) AS csid, COUNT(s.id) AS n')
                ->andWhere('s.classSubject IN (:ids)')
                ->andWhere('s.term = :term')
                ->andWhere('s.status = :submitted')
                ->setParameter('ids', $ids)
                ->setParameter('term', $term)
                ->setParameter('submitted', ResultComponentScore::STATUS_SUBMITTED)
                ->groupBy('s.classSubject')
                ->getQuery()
                ->getArrayResult() as $row) {
                $submitted[(int) $row['csid']] = (int) $row['n'];
            }
        }

        foreach ($classSubjects as $classSubject) {
            $id = $classSubject->getId();
            $approval = $approvals[$id] ?? null;
            $status = $stage === 'year_group'
                ? $approval?->getYearGroupStatus()
                : $approval?->getHodStatus();

            if ($status === ResultApproval::STATUS_APPROVED) {
                $stats['approved']++;
                continue;
            }
            if ($status === ResultApproval::STATUS_REJECTED) {
                $stats['returned']++;
                continue;
            }
            if (($submitted[$id] ?? 0) > 0) {
                $stats['submitted']++;
                $stats['waiting']++;
            }
        }

        return $stats;
    }

    /**
     * Pupils in a class: enrolment is authoritative, `currentClassroom` is the
     * fallback for a class that has not been promoted into enrolments yet.
     *
     * @return list<int>
     */
    private function rosterIds(Classroom $classroom, Term $term): array
    {
        $session = $term->getSession();

        $ids = [];
        if ($session) {
            foreach ($this->em->getRepository(Enrollment::class)->findBy([
                'classroom' => $classroom,
                'session' => $session,
            ]) as $enrollment) {
                if ($enrollment->getStudent()) {
                    $ids[] = (int) $enrollment->getStudent()->getId();
                }
            }
        }

        if ($ids === []) {
            foreach ($this->em->getRepository(Student::class)->findBy(['currentClassroom' => $classroom]) as $student) {
                $ids[] = (int) $student->getId();
            }
        }

        return $ids;
    }

    /**
     * @return array{0: int, 1: int} [class-subjects this session, how many fully approved]
     */
    private function resultReadiness(?Term $term): array
    {
        $session = $term?->getSession();
        if (!$term || !$session) {
            return [0, 0];
        }

        $total = (int) $this->em->getRepository(ClassSubject::class)->count(['session' => $session]);
        if ($total === 0) {
            return [0, 0];
        }

        $approved = (int) $this->em->getRepository(ResultApproval::class)->createQueryBuilder('ra')
            ->select('COUNT(ra.id)')
            ->join('ra.classSubject', 'cs')
            ->andWhere('ra.term = :term')
            ->andWhere('cs.session = :session')
            ->andWhere('ra.yearGroupStatus = :approved')
            ->setParameter('term', $term)
            ->setParameter('session', $session)
            ->setParameter('approved', ResultApproval::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        return [$total, $approved];
    }

    private function collectedToday(): float
    {
        $today = new \DateTimeImmutable('today 00:00:00');

        return $this->money(
            'SELECT SUM(p.amount) FROM ' . Payment::class . ' p'
            . ' WHERE p.createdAt >= :today AND p.status = :confirmed',
            ['today' => $today, 'confirmed' => Payment::STATUS_CONFIRMED]
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function money(string $dql, array $params = []): float
    {
        $query = $this->em->createQuery($dql);
        foreach ($params as $key => $value) {
            $query->setParameter($key, $value);
        }

        return (float) ($query->getSingleScalarResult() ?? 0);
    }
}
