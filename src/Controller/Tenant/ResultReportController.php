<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\ReportShareToken;
use App\Entity\Tenant\ReportArchive;
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
use App\Service\Results\ReportAccessGate;
use App\Service\Results\ReportArchiveService;
use App\Service\Results\ReportPdfRendererInterface;
use App\Service\Results\ReportShareService;
use App\Service\Results\ResultMailer;
use App\Service\Results\ResultSheetBuilder;
use App\Service\Results\TranscriptPdfRenderer;
use App\Service\SchoolLife\TermRelease;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reports')]
#[IsGranted('ROLE_USER')]
class ResultReportController extends AbstractController
{
    private ?array $cachedGradingScales = null;

    public function __construct(
        private EntityManagerInterface $em,
        private ResultSheetBuilder $sheetBuilder,
        private ReportPdfRendererInterface $pdfRenderer,
        private ReportShareService $shareService,
        private ReportAccessGate $gate,
        private ResultMailer $mailer,
        private TranscriptPdfRenderer $transcriptRenderer,
        private ReportArchiveService $archives,
        private TermRelease $release,
    ) {
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
    public function studentSheet(Request $request): Response
    {
        $students = $this->studentsForCurrentUser();
        if (!$students) {
            return $this->noLinkedStudents();
        }

        // One term at a time: the card is a document for a term, not a transcript.
        // Defaults to the active term; ?term=<id> lets a parent look back.
        $termId = $request->query->getInt('term');
        $term = $termId > 0
            ? $this->em->getRepository(Term::class)->find($termId)
            : $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        if (!$term) {
            throw $this->createNotFoundException('No academic term is available yet.');
        }

        $sheets = [];
        $shares = [];
        $shareUrls = [];
        $shareNotices = [];
        $emailNotices = [];
        foreach ($students as $student) {
            $studentId = (int) $student->getId();
            $withheld = !$this->isGranted('ROLE_ADMIN') && $this->gate->isWithheld($student);

            // Unapproved marks are not a report card. Staff may preview a sheet
            // that is still in the approval chain; a parent may not see one.
            $released = $this->classroomOf($student) && $this->release->isReleased($this->classroomOf($student), $term);
            $maySee = $released || $this->isGranted('ROLE_TEACHER');

            if ($withheld) {
                $sheets[] = ['withheld' => true, 'student' => $student, 'outstanding' => $this->gate->outstanding($student)];
            } elseif (!$maySee) {
                $sheets[] = ['not_released' => true, 'student' => $student];
            } else {
                $sheets[] = $this->sheetBuilder->build($student, $term);
            }
            $shares[$studentId] = $withheld ? [] : $this->shareService->activeFor($student, $term);

            // Session, not flash: base.html.twig renders every flash bag before
            // the page body, which would swallow a card-specific panel.
            $url = $request->getSession()->remove('report_share_url_' . $studentId);
            if ($url) {
                $shareUrls[$studentId] = $url;
            }
            $notice = $request->getSession()->remove('report_share_notice_' . $studentId);
            if ($notice) {
                $shareNotices[$studentId] = $notice;
            }
            $emailNotice = $request->getSession()->remove('report_email_notice_' . $studentId);
            if ($emailNotice) {
                $emailNotices[$studentId] = $emailNotice;
            }
        }

        return $this->render('tenant/reports/card.html.twig', [
            'sheets' => $sheets,
            'shares' => $shares,
            'share_urls' => $shareUrls,
            'share_notices' => $shareNotices,
            'email_notices' => $emailNotices,
            'term' => $term,
        ]);
    }

    /**
     * The PDF is the artefact that gets printed, forwarded and shown to relatives.
     * It is rendered from the same ResultSheetBuilder structure as the screen, so a
     * parent can never download different figures from the ones they just read.
     */
    private function classroomOf(Student $student): ?Classroom
    {
        return $student->getCurrentClass();
    }

    #[Route('/student/pdf', name: 'app_tenant_report_student_pdf', methods: ['GET'])]
    public function studentPdf(Request $request): Response
    {
        [$student, $term] = $this->studentAndTerm($request);

        if (!$this->isGranted('ROLE_ADMIN') && $this->gate->isWithheld($student)) {
            $response = $this->render('tenant/reports/withheld.html.twig', [
                'student' => $student,
                'term' => $term,
                'school' => $this->gate->school(),
                'outstanding' => $this->gate->outstanding($student),
            ]);
            $response->setStatusCode(Response::HTTP_PAYMENT_REQUIRED);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $classroom = $this->classroomOf($student);
        if (!$this->isGranted('ROLE_TEACHER') && (!$classroom || !$this->release->isReleased($classroom, $term))) {
            $response = $this->render('tenant/reports/not_released.html.twig', [
                'student' => $student,
                'term' => $term,
                'school' => $this->gate->school(),
            ]);
            $response->setStatusCode(Response::HTTP_CONFLICT);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $sheet = $this->sheetBuilder->build($student, $term);

        if ($sheet['empty'] ?? false) {
            throw $this->createNotFoundException('There is no report card for this pupil and term.');
        }

        $pdf = $this->pdfRenderer->render($sheet);
        $this->archives->record($student, $term, ReportArchive::KIND_CARD, $pdf, $this->getUser()?->getUserIdentifier());

        $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $student->getFullName()) ?: 'pupil';
        $termName = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $term->getName()) ?: 'term';
        $filename = sprintf('report-%s-%s.pdf', trim($name, '-'), trim($termName, '-'));

        $disposition = $request->query->getBoolean('inline') ? 'inline' : 'attachment';
        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $disposition . '; filename="' . $filename . '"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Creates a signed 30-day link the parent can send on WhatsApp.
     *
     * Refused unless the term is final-approved: the public link is the point at
     * which a figure leaves the school, so it must be a figure the school signed
     * off (RESULT-CARD-DESIGN.md §9 items 1 and 3).
     */
    #[Route('/student/share', name: 'app_tenant_report_student_share', methods: ['POST'])]
    public function shareStudentSheet(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('report_share', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('That form expired. Reload the page and try again.');
        }

        [$student, $term] = $this->studentAndTerm($request);

        if ($this->gate->isWithheld($student)) {
            $this->addFlash('error', 'A share link cannot be created while outstanding fees are on the account.');
            return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
        }

        $sheet = $this->sheetBuilder->build($student, $term);

        if ($sheet['empty'] ?? false) {
            $this->addFlash('report_error_' . $student->getId(), 'There is no report card to share for this term.');
            return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
        }

        if (!($sheet['released'] ?? false)) {
            $this->addFlash(
                'report_error_' . $student->getId(),
                'Sharing opens when the term is final-approved. The card is still in progress.'
            );
            return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        $created = $this->shareService->create($student, $term, $user);

        $url = $this->generateUrl(
            'app_tenant_report_shared',
            ['token' => $created['token']],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $request->getSession()->set('report_share_url_' . $student->getId(), $url);

        return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
    }

    /**
     * Email the PDF to the parent who asked for it. The attachment is the same
     * document the Download button produces, including its verification code.
     */
    #[Route('/student/email', name: 'app_tenant_report_student_email', methods: ['POST'])]
    public function emailStudentSheet(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('report_email', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('That form expired. Reload the page and try again.');
        }

        [$student, $term] = $this->studentAndTerm($request);

        if ($this->gate->isWithheld($student)) {
            $request->getSession()->set('report_email_notice_' . $student->getId(), 'The report cannot be emailed while outstanding fees remain.');
            return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
        }

        $sheet = $this->sheetBuilder->build($student, $term);
        if ($sheet['empty'] ?? false) {
            $request->getSession()->set('report_email_notice_' . $student->getId(), 'There is no report card to email for this term.');
            return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
        }

        $guardian = $student->getGuardian();
        $user = $this->getUser();
        $toEmail = $guardian?->getEmail() ?: ($user instanceof User ? $user->getEmail() : null);
        $toName = $guardian?->getFullName() ?: ($user instanceof User ? $user->getFullName() : null);

        $pdf = $this->pdfRenderer->render($sheet);
        $filename = sprintf(
            'report-%s-%s.pdf',
            trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $student->getFullName()), '-') ?: 'pupil',
            trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $term->getName()), '-') ?: 'term'
        );

        $school = $sheet['school'] ?? $this->gate->school();
        if (!$school) {
            $request->getSession()->set('report_email_notice_' . $student->getId(), 'The school profile is incomplete; the report could not be emailed.');
            return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
        }

        $this->archives->record($student, $term, ReportArchive::KIND_CARD, $pdf, $this->getUser()?->getUserIdentifier());
        $sent = $this->mailer->send($school, $student, $term, $pdf, $filename, $toEmail, $toName);

        $request->getSession()->set(
            'report_email_notice_' . $student->getId(),
            $sent
                ? 'Report emailed to ' . $toEmail . '.'
                : 'We could not send that email. Check the address on the parent record or try again later.'
        );

        return $this->redirectToRoute('app_tenant_report_student', ['term' => $term->getId()]);
    }

    /**
     * The session transcript: all terms, all subjects, one printable record.
     */
    #[Route('/student/transcript', name: 'app_tenant_report_student_transcript', methods: ['GET'])]
    public function studentTranscript(Request $request): Response
    {
        [$student, $term] = $this->studentAndTerm($request);

        if (!$this->isGranted('ROLE_ADMIN') && $this->gate->isWithheld($student)) {
            return $this->withheldResponse($student, $term);
        }

        $sheet = $this->sheetBuilder->build($student, $term);
        if ($sheet['empty'] ?? false) {
            throw $this->createNotFoundException('There is no transcript for this pupil and term.');
        }

        return $this->render('tenant/reports/transcript.html.twig', [
            'sheet' => $sheet,
            'term' => $term,
        ]);
    }

    #[Route('/student/transcript/pdf', name: 'app_tenant_report_student_transcript_pdf', methods: ['GET'])]
    public function studentTranscriptPdf(Request $request): Response
    {
        [$student, $term] = $this->studentAndTerm($request);

        if (!$this->isGranted('ROLE_ADMIN') && $this->gate->isWithheld($student)) {
            return $this->withheldResponse($student, $term);
        }

        $sheet = $this->sheetBuilder->build($student, $term);
        if ($sheet['empty'] ?? false) {
            throw $this->createNotFoundException('There is no transcript for this pupil and term.');
        }

        $pdf = $this->transcriptRenderer->render($sheet);
        $this->archives->record($student, $term, ReportArchive::KIND_TRANSCRIPT, $pdf, $this->getUser()?->getUserIdentifier());
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $student->getFullName()) ?: 'pupil';

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            ($request->query->getBoolean('inline') ? 'inline' : 'attachment') . '; filename="transcript-' . trim($name, '-') . '.pdf"'
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * PTA-day batch print: every report in the class, one per printed page.
     * This is the printer-friendly screen; it deliberately does not create a
     * single giant PDF, because a browser print dialog already paginates.
     */
    #[Route('/class/{classroom}/print', name: 'app_tenant_report_class_print', methods: ['GET'])]
    #[IsGranted('ROLE_TEACHER')]
    public function classPrint(Classroom $classroom, Request $request): Response
    {
        $term = $request->query->getInt('term') > 0
            ? $this->em->getRepository(Term::class)->find($request->query->getInt('term'))
            : $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        if (!$term) {
            throw $this->createNotFoundException('No academic term is available.');
        }

        if (!$this->isGranted('ROLE_ADMIN')) {
            $staff = $this->currentStaff();
            $assignment = $staff ? $this->em->getRepository(FormTeacher::class)->findOneBy([
                'staff' => $staff,
                'term' => $term,
            ]) : null;

            if (!$assignment || (int) $assignment->getClassroom()?->getId() !== (int) $classroom->getId()) {
                throw $this->createAccessDeniedException('Only the form teacher or an administrator can print this class.');
            }
        }

        $sheets = [];
        foreach ($this->studentsForClassroom($classroom, $term) as $student) {
            $withheld = !$this->isGranted('ROLE_ADMIN') && $this->gate->isWithheld($student);
            $sheets[] = $withheld
                ? ['withheld' => true, 'student' => $student, 'outstanding' => $this->gate->outstanding($student)]
                : $this->sheetBuilder->build($student, $term);
        }

        return $this->render('tenant/reports/class_print.html.twig', [
            'classroom' => $classroom,
            'term' => $term,
            'sheets' => $sheets,
        ]);
    }

    /** A parent can withdraw a link they have already sent. */
    #[Route('/student/share/{id}/revoke', name: 'app_tenant_report_student_share_revoke', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function revokeStudentShare(ReportShareToken $share, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('report_share_revoke_' . $share->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('That form expired. Reload the page and try again.');
        }

        $student = $share->getStudent();
        if (!$student) {
            throw $this->createNotFoundException('No such share link.');
        }
        $this->assertStudentAccess($student);

        $this->shareService->revoke($share);
        $request->getSession()->set('report_share_notice_' . $student->getId(), 'That link has been revoked.');

        return $this->redirectToRoute('app_tenant_report_student', [
            'term' => $share->getTerm()?->getId(),
        ]);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function withheldResponse(Student $student, Term $term): Response
    {
        $response = $this->render('tenant/reports/withheld.html.twig', [
            'student' => $student,
            'term' => $term,
            'school' => $this->gate->school(),
            'outstanding' => $this->gate->outstanding($student),
        ]);
        $response->setStatusCode(Response::HTTP_PAYMENT_REQUIRED);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

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

    /**
     * Resolve the pupil and term for a card request, enforcing the guardian link.
     * A query-string id is never trusted on its own.
     *
     * @return array{0: Student, 1: Term}
     */
    private function studentAndTerm(Request $request): array
    {
        $students = $this->studentsForCurrentUser();
        if (!$students) {
            throw $this->createAccessDeniedException('No student records are linked to your account.');
        }

        $studentId = $request->query->getInt('student') ?: $request->request->getInt('student');
        $student = null;
        foreach ($students as $candidate) {
            if ($studentId > 0 && (int) $candidate->getId() === $studentId) {
                $student = $candidate;
                break;
            }
        }
        if (!$student) {
            if (count($students) === 1) {
                $student = $students[0];
            } else {
                throw $this->createNotFoundException('Choose which child this report is for.');
            }
        }

        $termId = $request->query->getInt('term') ?: $request->request->getInt('term');
        $term = $termId > 0
            ? $this->em->getRepository(Term::class)->find($termId)
            : $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        if (!$term) {
            throw $this->createNotFoundException('No academic term is available yet.');
        }

        return [$student, $term];
    }

    /**
     * @return list<Student>
     */
    private function studentsForClassroom(Classroom $classroom, Term $term): array
    {
        $session = $term->getSession();
        $students = [];

        if ($session) {
            foreach ($this->em->getRepository(\App\Entity\Tenant\Enrollment::class)->findBy([
                'classroom' => $classroom,
                'session' => $session,
            ]) as $enrollment) {
                if ($enrollment->getStudent()) {
                    $students[(int) $enrollment->getStudent()->getId()] = $enrollment->getStudent();
                }
            }
        }

        if ($students === []) {
            foreach ($this->em->getRepository(Student::class)->findBy(['currentClassroom' => $classroom]) as $student) {
                $students[(int) $student->getId()] = $student;
            }
        }

        uasort($students, static fn (Student $a, Student $b) => strcasecmp(
            ($a->getLastName() ?? '') . ' ' . ($a->getFirstName() ?? ''),
            ($b->getLastName() ?? '') . ' ' . ($b->getFirstName() ?? '')
        ));

        return array_values($students);
    }

    private function assertStudentAccess(Student $student): void
    {
        foreach ($this->studentsForCurrentUser() as $candidate) {
            if ((int) $candidate->getId() === (int) $student->getId()) {
                return;
            }
        }

        throw $this->createAccessDeniedException('That pupil is not linked to your account.');
    }

    /**
     * A staff account has no pupils of its own, so "My Results" is simply the wrong
     * page for it. Send staff to the report tools with an explanation, and show a
     * parent a calm page that says what to do next — never a raw Symfony 403.
     */
    private function noLinkedStudents(): Response
    {
        $user = $this->getUser();

        // A staff account has no pupils of its own. Some admin accounts have no
        // Staff row at all, so the role is the reliable signal — ROLE_PARENT is
        // the only role that should ever reach a child's card.
        if ($user instanceof User && ($user->getStaff() !== null || !$this->isGranted('ROLE_PARENT'))) {
            $this->addFlash(
                'info',
                'Report cards are shown to parent accounts. Use the report tools below to review results.'
            );

            return $this->redirectToRoute(
                $this->isGranted('ROLE_TEACHER') ? 'app_tenant_reports_hub' : 'app_tenant_dashboard'
            );
        }

        $response = $this->render('tenant/reports/no_students.html.twig');
        $response->setStatusCode(Response::HTTP_FORBIDDEN);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
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
