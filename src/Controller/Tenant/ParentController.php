<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use App\Entity\Tenant\SchoolEvent;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\Payment; // 👈 Needed for payment history
use App\Entity\Tenant\Application; // 👈 Online admission applications
use App\Entity\Tenant\PaymentIntent;
use App\Service\Fees\FeeReportService;
use App\Service\Fees\StudentStatementPdfRenderer;
use App\Service\Payment\OnlinePaymentAvailability;
use App\Service\Payment\PaymentChargePolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/parent')]
#[IsGranted('ROLE_PARENT')]
class ParentController extends AbstractController
{
    public function __construct(
        private OnlinePaymentAvailability $onlinePayments,
        private PaymentChargePolicy $charges,
        private EntityManagerInterface $em,
    ) {
    }
    #[Route('/dashboard', name: 'app_parent_dashboard')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $guardian = $em->getRepository(Guardian::class)->findOneBy(['user' => $user]);

        // Online admission applications (docs/parent-application/DESIGN.md §8 item 4).
        // Shown even when the parent has no students yet — that is exactly the state
        // a freshly verified parent is in.
        $applications = $guardian
            ? $em->getRepository(Application::class)->findBy(
                ['guardian' => $guardian],
                ['createdAt' => 'DESC'],
                3
            )
            : [];
        $applicationCount = $guardian
            ? $em->getRepository(Application::class)->count(['guardian' => $guardian])
            : 0;
        $school = $em->getRepository(School::class)->findOneBy([]);

        if (!$guardian) {
            return $this->render('tenant/parent/dashboard.html.twig', [
                'studentData' => [],
                'flashEvents' => [],
                'applications' => [],
                'applicationCount' => 0,
                'applicationsOpen' => $school?->isApplicationsOpen() ?? true,
            ]);
        }

        $students = $guardian->getStudents();
        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        // 🟢 NEW: FETCH FLASH NOTICES (URGENT ALERTS)
        // We only fetch events where isFlashNotice is true and are happening today or later
        $flashEvents = $em->getRepository(SchoolEvent::class)->createQueryBuilder('e')
            ->where('e.isFlashNotice = :flash')
            ->andWhere('e.startDate >= :today')
            ->setParameter('flash', true)
            ->setParameter('today', new \DateTime('today')) // 'today' ensures it shows events happening later today
            ->orderBy('e.startDate', 'ASC')
            ->setMaxResults(3) // Only show the 3 most urgent items
            ->getQuery()
            ->getResult();

        // PREPARE DATA
        $studentData = [];
        foreach ($students as $student) {
            $invoice = $em->getRepository(Invoice::class)->findOneBy([
                'student' => $student,
                'term' => $term,
            ]);

            $lastPayment = null;
            if ($invoice) {
                $lastPayment = $em->getRepository(Payment::class)->findOneBy(
                    ['invoice' => $invoice],
                    ['createdAt' => 'DESC']
                );
            }

            $studentData[] = [
                'student' => $student,
                'invoice' => $invoice,
                'lastPayment' => $lastPayment
            ];
        }

        return $this->render('tenant/parent/dashboard.html.twig', [
            'studentData' => $studentData,
            'term' => $term,
            'flashEvents' => $flashEvents, // 👈 Passed to the view
            'applications' => $applications,
            'applicationCount' => $applicationCount,
            'applicationsOpen' => $school?->isApplicationsOpen() ?? true,
        ]);
    }
    
    // ... (Your existing calendar method is perfect, keep it as is!) ...
    #[Route('/calendar/{year}/{month}', name: 'app_parent_calendar', requirements: ['year' => '\d+', 'month' => '\d+'])]
    public function calendar(EntityManagerInterface $em, ?int $year = null, ?int $month = null): Response
    {
        // ... (Keep existing code) ...
        $today = new \DateTime();
        $year = $year ?? (int)$today->format('Y');
        $month = $month ?? (int)$today->format('m');

        $startOfMonth = new \DateTimeImmutable("$year-$month-01 00:00:00");
        $endOfMonth = $startOfMonth->modify('last day of this month')->setTime(23, 59, 59);

        $events = $em->getRepository(SchoolEvent::class)->createQueryBuilder('e')
            ->where('e.startDate >= :start')
            ->andWhere('e.startDate <= :end')
            ->setParameter('start', $startOfMonth)
            ->setParameter('end', $endOfMonth)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('tenant/parent/calendar.html.twig', [
            'currentMonth' => $startOfMonth,
            'events' => $events, 
            'today' => $today,
        ]);
    }

    #[Route('/student/{id}/finance', name: 'app_parent_finance_view')]
    public function finance(Student $student, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $guardian = $em->getRepository(Guardian::class)->findOneBy(['user' => $user]);

        // 1. SECURITY: Ensure the parent actually owns this student
        if (!$guardian || !$guardian->getStudents()->contains($student)) {
            throw $this->createAccessDeniedException('You are not authorized to view this student.');
        }
        // 2. Fetch Invoices (Newest first)
        $invoices = $em->getRepository(Invoice::class)->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC']
        );

        // 3. Fetch Payments (Newest first)
        // We can find payments by joining through invoices, or if you have a direct link
        // For simplicity, let's just grab payments linked to these invoices
        $payments = [];
        foreach ($invoices as $inv) {
            foreach ($inv->getPayments() as $p) {
                $payments[] = $p;
            }
        }
        
        // Sort payments by date (descending)
        usort($payments, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        // What the parent would actually be charged online — balance plus the
        // (capped) processing charge, or just the balance when the school has
        // switched the charge off. Shown on the button, so nobody taps "Pay"
        // and discovers the number at the provider's page.
        $school = $em->getRepository(School::class)->findOneBy([]);
        $onlineTotals = [];
        $onlineCharges = [];
        foreach ($invoices as $invoice) {
            if ($invoice->getBalanceDue() <= 0) {
                continue;
            }
            $onlineTotals[$invoice->getId()] = $this->charges->totalFor($school, (float) $invoice->getBalanceDue());
            $onlineCharges[$invoice->getId()] = $this->charges->feeFor($school, (float) $invoice->getBalanceDue());
        }

        return $this->render('tenant/parent/finance_view.html.twig', [
            'student' => $student,
            'invoices' => $invoices,
            'payments' => $payments,
            // The pay button only appears when a payment could actually complete.
            'onlinePaymentAvailable' => $this->onlinePaymentAvailable(),
            'onlineTotals' => $onlineTotals,
            'onlineCharges' => $onlineCharges,
        ]);
    }

    /**
     * "Fees" — the destination behind the bottom tab bar.
     *
     * Fees are per child, and most families have one, so going straight to that
     * child's ledger beats making every parent walk Dashboard → child → History.
     * Multi-child families land on the dashboard, which lists each child.
     */
    #[Route('/fees', name: 'app_parent_fees_index')]
    public function fees(): Response
    {
        $guardian = $this->em->getRepository(Guardian::class)->findOneBy(['user' => $this->getUser()]);
        $student = $guardian?->getStudents()->first() ?: null;

        if (!$student instanceof Student) {
            $this->addFlash('info', 'No child is linked to your account yet.');

            return $this->redirectToRoute('app_parent_dashboard');
        }

        return $this->redirectToRoute('app_parent_finance_view', ['id' => $student->getId()]);
    }

    /**
     * The child's Statement of Account, as an A4 PDF a parent can print, hand to
     * a bank, or forward to a relative helping with fees.
     *
     * The amounts come from FeeReportService — the same ledger the bursar's
     * statement screen uses — so the paper and the screen can never disagree.
     * `?format=html` renders the exact same context for on-screen proof.
     */
    #[Route('/student/{id}/statement.pdf', name: 'app_parent_statement_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function statementPdf(
        Student $student,
        Request $request,
        FeeReportService $reports,
        StudentStatementPdfRenderer $renderer,
    ): Response {
        $guardian = $this->em->getRepository(Guardian::class)->findOneBy(['user' => $this->getUser()]);

        if (!$guardian || !$guardian->getStudents()->contains($student)) {
            throw $this->createAccessDeniedException('You are not authorized to view this student.');
        }

        // `all` (the default) covers every term on record — a parent asking for a
        // statement wants the whole account, not just the current term.
        $termParam = (string) $request->query->get('term', 'all');
        $term = null;
        if ($termParam !== '' && $termParam !== 'all') {
            $term = $this->em->getRepository(Term::class)->find((int) $termParam);
            if ($term === null) {
                throw $this->createNotFoundException('That term does not exist.');
            }
        }

        $school = $this->em->getRepository(School::class)->findOneBy([]);
        if ($school === null) {
            throw $this->createNotFoundException('This school has not been configured yet.');
        }

        // Store purchases are part of the child's account, so they are included
        // by default; `?store=0` narrows it to tuition and levies only.
        $statement = $reports->statement($student, $term, $request->query->getBoolean('store', true));

        $context = $renderer->context(
            $student,
            $school,
            $statement,
            $term,
            $guardian,
            (string) ($request->attributes->get('subdomain') ?: explode('.', $request->getHost())[0])
        );

        if ($request->query->get('format') === 'html') {
            return $this->render('tenant/report/fees/statement.pdf.twig', $context);
        }

        $pdf = $renderer->render($context);

        $reference = preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($student->getAdmissionNumber() ?? $student->getId())) ?? 'statement';
        $filename = sprintf(
            'statement-%s-%s.pdf',
            trim($reference, '-'),
            (new \DateTimeImmutable())->format('Y-m-d')
        );

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Length'      => (string) strlen($pdf),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $request->query->getBoolean('download') ? 'attachment' : 'inline',
                $filename
            ),
            'Cache-Control'       => 'private, no-cache, must-revalidate',
        ]);
    }

    /**
     * Can this school take money online right now?
     *
     * The gateway must be configured, the school must have opted in, and a real
     * provider needs somewhere to send the school's money. Showing a "Pay online"
     * button that dies half way through is worse than not showing one.
     */
    private function onlinePaymentAvailable(): bool
    {
        return $this->onlinePayments->isAvailable(
            $this->em->getRepository(School::class)->findOneBy([])
        );
    }
}