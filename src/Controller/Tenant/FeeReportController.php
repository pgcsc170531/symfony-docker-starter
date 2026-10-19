<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Expense;
use App\Entity\Tenant\ExpenseCategory;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Service\Fees\FeeReportPdfRenderer;
use App\Service\Fees\FeeReportService;
use App\Service\Fees\StudentStatementPdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The fee reporting section — Phase 1 of
 * docs/payments/FEES-REPORTING-DESIGN.md.
 *
 *   /finance/reports                 overview
 *   /finance/reports/collections     R1 daily cash book
 *   /finance/reports/classes         R2 billed vs collected per class
 *   /finance/reports/debtors         R3 families that still owe
 *   /finance/reports/statement/{id}  R4 one pupil's ledger
 *
 * Every figure comes from FeeReportService. Do not add money up in Twig.
 */
#[Route('/finance/reports')]
#[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_ADMIN')"))]
final class FeeReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeeReportService $reports,
    ) {
    }

    #[Route('', name: 'app_tenant_report_fees', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $term = $this->currentTerm($request);

        return $this->render('tenant/report/fees/index.html.twig', [
            'term'   => $term,
            'terms'  => $this->terms(),
            'totals' => $term ? $this->reports->termTotals($term) : null,
        ]);
    }

    #[Route('/collections', name: 'app_tenant_report_collections', methods: ['GET'])]
    public function collections(Request $request): Response
    {
        $raw = trim((string) $request->query->get('date', ''));
        $date = $raw !== ''
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: new \DateTimeImmutable('today'))
            : new \DateTimeImmutable('today');

        return $this->render('tenant/report/fees/collections.html.twig', [
            'date'     => $date,
            'totals'   => $this->reports->dailyCollections($date),
            'payments' => $this->reports->dailyPayments($date),
        ]);
    }

    #[Route('/classes', name: 'app_tenant_report_classes', methods: ['GET'])]
    public function classes(Request $request): Response
    {
        $term = $this->currentTerm($request);

        $unbilled = $term !== null
            ? $this->em->getRepository(Invoice::class)->pupilsWithoutInvoice($term)
            : [];

        return $this->render('tenant/report/fees/classes.html.twig', [
            'term'      => $term,
            'terms'     => $this->terms(),
            'rows'      => $term ? $this->reports->classCollection($term) : [],
            'totals'    => $term ? $this->reports->termTotals($term) : null,
            'unbilled'  => $unbilled,
        ]);
    }

    #[Route('/debtors', name: 'app_tenant_report_debtors', methods: ['GET'])]
    public function debtors(Request $request): Response
    {
        $term = $this->currentTerm($request);
        $classroom = $this->selectedClassroom($request);

        $families = $term ? $this->reports->guardianDebtors($term, $classroom) : [];

        return $this->render('tenant/report/fees/debtors.html.twig', [
            'term'       => $term,
            'terms'      => $this->terms(),
            'families'   => $families,
            'classrooms' => $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'classroom'  => $classroom,
            'totalOwed'  => array_sum(array_column($families, 'outstanding')),
        ]);
    }

    /**
     * The reminder list as CSV — the bursar sends the messages, never the system.
     */
    #[Route('/debtors.csv', name: 'app_tenant_report_debtors_csv', methods: ['GET'])]
    public function debtorsCsv(Request $request): StreamedResponse
    {
        $term = $this->currentTerm($request);
        $classroom = $this->selectedClassroom($request);
        $families = $term ? $this->reports->guardianDebtors($term, $classroom) : [];
        $period = $term ? (($term->getSession()?->getName() ?? '') . ' · ' . $term->getName()) : '';

        $rows = array_map(static function (array $family) use ($period): array {
            $children = implode(', ', array_map(
                static fn (array $child): string => $child['pupil'] . ($child['class'] ? ' (' . $child['class'] . ')' : ''),
                $family['children']
            ));

            $message = sprintf(
                'Dear %s, our records show N%s outstanding for %s. Kindly settle with the school office.',
                $family['guardian'] ?? 'Guardian',
                number_format($family['outstanding'], 2),
                $period
            );

            return [
                $family['guardian'] ?? 'No guardian assigned',
                $family['phone'] ?? '',
                $family['pupils'],
                $children,
                number_format($family['outstanding'], 2, '.', ''),
                $message,
            ];
        }, $families);

        return $this->csv('outstanding-' . ($term?->getId() ?? 'term') . '.csv', [
            'Guardian', 'Phone', 'Pupils', 'Children', 'Outstanding', 'Message',
        ], $rows);
    }

    #[Route('/statement/{id}', name: 'app_tenant_report_statement', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function statement(Student $student, Request $request): Response
    {
        $term = null;
        $termId = (string) $request->query->get('term', '');

        if ($termId !== 'all') {
            $term = $termId !== ''
                ? $this->em->getRepository(Term::class)->find((int) $termId)
                : $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        }

        return $this->render('tenant/report/fees/statement.html.twig', [
            'school'       => $this->em->getRepository(School::class)->findOneBy([]),
            'data'         => $this->reports->statement($student, $term, $request->query->getBoolean('store')),
            'term'         => $term,
            'terms'        => $this->terms(),
            'includeStore' => $request->query->getBoolean('store'),
        ]);
    }

    /**
     * The same statement as an issued A4 document — masthead, QR and verification
     * code — for a bursar to print or email. Shares its renderer with the parent
     * download in ParentController, so the two can never drift apart.
     */
    #[Route('/statement/{id}.pdf', name: 'app_tenant_report_statement_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function statementPdf(Student $student, Request $request, StudentStatementPdfRenderer $renderer): Response
    {
        $term = null;
        $termId = (string) $request->query->get('term', 'all');

        if ($termId !== '' && $termId !== 'all') {
            $term = $this->em->getRepository(Term::class)->find((int) $termId);
            if ($term === null) {
                throw $this->createNotFoundException('That term does not exist.');
            }
        }

        $school = $this->em->getRepository(School::class)->findOneBy([]);
        if ($school === null) {
            throw $this->createNotFoundException('This school has not been configured yet.');
        }

        $includeStore = $request->query->getBoolean('store');
        $context = $renderer->context(
            $student,
            $school,
            $this->reports->statement($student, $term, $includeStore),
            $term,
            $student->getGuardian(),
            (string) ($request->attributes->get('subdomain') ?: explode('.', $request->getHost())[0])
        );

        if ($request->query->get('format') === 'html') {
            return $this->render('tenant/report/fees/statement.pdf.twig', $context);
        }

        $pdf = $renderer->render($context);
        $reference = preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($student->getAdmissionNumber() ?? $student->getId())) ?? 'statement';
        $filename = sprintf('statement-%s-%s.pdf', trim($reference, '-'), (new \DateTimeImmutable())->format('Y-m-d'));

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

    // ──────────────────────────────────────────────────────────────────
    //  Phase 2 — register, reconciliation, matrix, expenses
    // ──────────────────────────────────────────────────────────────────

    #[Route('/payments', name: 'app_tenant_report_payments', methods: ['GET'])]
    public function payments(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $filters = $this->paymentFilters($request, $from, $to);

        $register = $this->reports->paymentRegister($filters, max(1, $request->query->getInt('page', 1)));

        return $this->render('tenant/report/fees/payments.html.twig', [
            'filters'     => $filters,
            'from'        => $from,
            'to'          => $to,
            'register'    => $register,
            'totals'      => $this->reports->paymentRegisterTotals($filters),
            'classrooms'  => $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'methods'     => [Payment::METHOD_CASH, Payment::METHOD_TRANSFER, Payment::METHOD_POS, Payment::METHOD_CHEQUE],
            'statuses'    => [Payment::STATUS_CONFIRMED, Payment::STATUS_PENDING, Payment::STATUS_DECLINED, Payment::STATUS_CANCELLED],
        ]);
    }

    #[Route('/payments.csv', name: 'app_tenant_report_payments_csv', methods: ['GET'])]
    public function paymentsCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);
        $filters = $this->paymentFilters($request, $from, $to);
        $rows = $this->reports->paymentRegister($filters, 1, 100000)['rows'];

        return $this->csv('payments-' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.csv', [
            'Date', 'Time', 'Pupil / buyer', 'Admission no.', 'Class', 'Invoice', 'Type',
            'Method', 'Reference', 'Amount', 'Status', 'Confirmed by',
        ], array_map(static function (Payment $p): array {
            $invoice = $p->getInvoice();
            $student = $invoice?->getStudent();

            return [
                ($p->getPaidAt() ?? $p->getCreatedAt())?->format('Y-m-d'),
                ($p->getPaidAt() ?? $p->getCreatedAt())?->format('H:i'),
                $student?->getFullName() ?? $invoice?->getBuyerName() ?? 'Walk-in customer',
                $student?->getAdmissionNumber() ?? '',
                $invoice?->getClassroom()?->getName() ?? '',
                $invoice?->getInvoiceNumber() ?? '',
                $invoice?->getType() ?? '',
                $p->getMethod() ?? '',
                $p->getReferenceCode() ?? '',
                number_format((float) $p->getAmount(), 2, '.', ''),
                $p->getStatus() ?? '',
                $p->getConfirmedBy() ?? '',
            ];
        }, $rows));
    }

    #[Route('/reconciliation', name: 'app_tenant_report_reconciliation', methods: ['GET'])]
    public function reconciliation(Request $request): Response
    {
        $term = $this->currentTerm($request);
        $data = $term ? $this->reports->reconciliation($term) : ['rows' => [], 'stale' => []];

        $confirmed = 0.0;
        $pending = 0.0;
        foreach ($data['rows'] as $row) {
            if ($row['status'] === Payment::STATUS_CONFIRMED) {
                $confirmed += $row['total'];
            } elseif ($row['status'] === Payment::STATUS_PENDING) {
                $pending += $row['total'];
            }
        }

        return $this->render('tenant/report/fees/reconciliation.html.twig', [
            'term'      => $term,
            'terms'     => $this->terms(),
            'rows'      => $data['rows'],
            'stale'     => $data['stale'],
            'confirmed' => $confirmed,
            'pending'   => $pending,
        ]);
    }

    #[Route('/matrix', name: 'app_tenant_report_matrix', methods: ['GET'])]
    public function matrix(Request $request): Response
    {
        $term = $this->currentTerm($request);
        $classrooms = $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']);
        $classroom = $this->selectedClassroom($request);

        // No class chosen? Land on a class that actually has invoices rather
        // than the first alphabetically, which may be empty.
        if ($classroom === null && $term !== null) {
            $sample = $this->em->getRepository(Invoice::class)->findOneBy([
                'term' => $term,
                'type' => Invoice::TYPE_ACADEMIC,
            ]);
            $classroom = $sample?->getClassroom();
        }
        $classroom ??= $classrooms[0] ?? null;

        $data = ($term !== null && $classroom !== null)
            ? $this->reports->classMatrix($term, $classroom)
            : ['rows' => [], 'summary' => ['paid' => 0, 'partial' => 0, 'unpaid' => 0, 'notBilled' => 0, 'total' => 0]];

        return $this->render('tenant/report/fees/matrix.html.twig', [
            'term'      => $term,
            'terms'     => $this->terms(),
            'classroom' => $classroom,
            'classrooms' => $classrooms,
            'data'      => $data,
        ]);
    }

    #[Route('/expenses', name: 'app_tenant_report_expenses', methods: ['GET'])]
    public function expenses(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $filters = $this->expenseFilters($request, $from, $to);

        return $this->render('tenant/report/fees/expenses.html.twig', [
            'filters'    => $filters,
            'from'       => $from,
            'to'         => $to,
            'register'   => $this->reports->expenseRegister($filters, max(1, $request->query->getInt('page', 1))),
            'totals'     => $this->reports->expenseRegisterTotals($filters),
            'categories' => $this->em->getRepository(ExpenseCategory::class)->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/expenses.csv', name: 'app_tenant_report_expenses_csv', methods: ['GET'])]
    public function expensesCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);
        $filters = $this->expenseFilters($request, $from, $to);
        $rows = $this->reports->expenseRegister($filters, 1, 100000)['rows'];

        return $this->csv('expenses-' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.csv', [
            'Date', 'Category', 'Title', 'Payee', 'Method', 'Note', 'Amount', 'Receipt', 'Recorded by',
        ], array_map(static fn (Expense $e): array => [
            $e->getExpenseDate()?->format('Y-m-d'),
            $e->getCategory()?->getName() ?? '',
            $e->getTitle() ?? '',
            $e->getPayee() ?? '',
            $e->getMethod() ?? '',
            $e->getNote() ?? '',
            number_format((float) $e->getAmount(), 2, '.', ''),
            $e->getReceiptFilename() ?? '',
            $e->getRecordedBy() ?? '',
        ], $rows));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Phase 3 — income statement, discounts, application fees, summary PDF
    // ──────────────────────────────────────────────────────────────────

    #[Route('/income', name: 'app_tenant_report_income', methods: ['GET'])]
    public function income(Request $request): Response
    {
        $term = $this->currentTerm($request);
        $previousTerm = $term ? $this->reports->previousTerm($term) : null;

        return $this->render('tenant/report/fees/income.html.twig', [
            'term'         => $term,
            'terms'        => $this->terms(),
            'statement'    => $term ? $this->reports->incomeStatement($term) : null,
            'previousTerm' => $previousTerm,
            'previous'     => $previousTerm ? $this->reports->incomeStatement($previousTerm) : null,
        ]);
    }

    #[Route('/discounts', name: 'app_tenant_report_discounts', methods: ['GET'])]
    public function discounts(Request $request): Response
    {
        $term = $this->currentTerm($request);

        return $this->render('tenant/report/fees/discounts.html.twig', [
            'term'  => $term,
            'terms' => $this->terms(),
            'data'  => $term ? $this->reports->discountReport($term) : [
                'rows'   => [],
                'totals' => ['waived' => 0.0, 'beneficiaries' => 0, 'assigned' => 0, 'missingInvoice' => 0],
            ],
        ]);
    }

    #[Route('/application-fees', name: 'app_tenant_report_application_fees', methods: ['GET'])]
    public function applicationFees(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $status = (string) $request->query->get('fee_status', '');
        $channel = (string) $request->query->get('channel', '');

        return $this->render('tenant/report/fees/application_fees.html.twig', [
            'from'         => $from,
            'to'           => $to,
            'status'       => $status,
            'channel'      => $channel,
            'totals'       => $this->reports->applicationFeeTotals($from, $to),
            'applications' => $this->reports->applicationFeeRegister($from, $to, $status, $channel),
            'statuses'     => [
                Application::FEE_UNPAID,
                Application::FEE_AWAITING_CONFIRMATION,
                Application::FEE_PAID,
                Application::FEE_WAIVED,
            ],
            'channels'     => [
                Application::CHANNEL_CASH,
                Application::CHANNEL_OFFLINE,
                Application::CHANNEL_ONLINE,
            ],
        ]);
    }

    /**
     * R9 — the Term Fee Summary PDF (and its `?format=html` proof).
     *
     * Context is built once, so the screen and the PDF share the verification
     * code, the QR and every figure.
     */
    #[Route('/summary.pdf', name: 'app_tenant_report_summary_pdf', methods: ['GET'])]
    public function summaryPdf(Request $request, FeeReportPdfRenderer $renderer): Response
    {
        $term = $this->currentTerm($request);
        if ($term === null) {
            throw $this->createNotFoundException('No term to summarise.');
        }

        $debtors = $this->reports->guardianDebtors($term);

        $context = $renderer->context([
            'term_id'      => $term->getId(),
            'subdomain'    => (string) ($request->attributes->get('subdomain') ?: explode('.', $request->getHost())[0]),
            'period'       => ($term->getSession()?->getName() ?? '—') . ' · ' . $term->getName(),
            'generated_at' => new \DateTimeImmutable(),
            'totals'       => $this->reports->termTotals($term),
            'statement'    => $this->reports->incomeStatement($term),
            'classes'      => $this->reports->classCollection($term),
            'debtors'      => array_slice($debtors, 0, 8),
        ], $this->em->getRepository(School::class)->findOneBy([]));

        if ($request->query->get('format') === 'html') {
            return $this->render('tenant/report/fees/summary.pdf.twig', $context);
        }

        $pdf = $renderer->render($context);
        $filename = sprintf('fee-summary-term-%s-%s.pdf', $term->getId(), (new \DateTimeImmutable())->format('Y-m-d'));

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

    // ──────────────────────────────────────────────────────────────────
    //  Filters
    // ──────────────────────────────────────────────────────────────────

    /** @return Term[] */
    private function terms(): array
    {
        return $this->em->getRepository(Term::class)->findBy([], ['id' => 'DESC']);
    }

    private function currentTerm(Request $request): ?Term
    {
        $id = $request->query->getInt('term');
        if ($id > 0) {
            $term = $this->em->getRepository(Term::class)->find($id);
            if ($term !== null) {
                return $term;
            }
        }

        return $this->em->getRepository(Term::class)->findOneBy(['isActive' => true])
            ?? $this->em->getRepository(Term::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function selectedClassroom(Request $request): ?Classroom
    {
        $id = $request->query->getInt('classroom');

        return $id > 0 ? $this->em->getRepository(Classroom::class)->find($id) : null;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function dateRange(Request $request): array
    {
        // Default to the active term, not "this month": a bursar opening the
        // register on the first day of a month should not see an empty page.
        $term = $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        $from = $this->parseDate((string) $request->query->get('from', ''))
            ?? $term?->getStartDate()?->modify('-30 days')
            ?? new \DateTimeImmutable('first day of this month 00:00:00');

        $to = $this->parseDate((string) $request->query->get('to', ''))?->setTime(23, 59, 59)
            ?? new \DateTimeImmutable('now 23:59:59');

        return [$from, $to];
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    /** @return array<string, mixed> */
    private function paymentFilters(Request $request, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [
            'from'      => $from,
            'to'        => $to,
            'classroom' => $this->selectedClassroom($request),
            'q'         => trim((string) $request->query->get('q', '')),
            'method'    => (string) $request->query->get('method', ''),
            'status'    => (string) $request->query->get('status', ''),
            'type'      => (string) $request->query->get('type', ''),
            'cashier'   => trim((string) $request->query->get('cashier', '')),
        ];
    }

    /** @return array<string, mixed> */
    private function expenseFilters(Request $request, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $categoryId = $request->query->getInt('category');
        $min = $request->query->get('min');

        return [
            'from'     => $from,
            'to'       => $to,
            'category' => $categoryId > 0 ? $this->em->getRepository(ExpenseCategory::class)->find($categoryId) : null,
            'q'        => trim((string) $request->query->get('q', '')),
            'min'      => ($min !== null && $min !== '') ? (float) $min : null,
        ];
    }

    /**
     * @param string[] $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // BOM so Excel opens the naira/UTF-8 text correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
}
