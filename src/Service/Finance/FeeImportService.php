<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FeeImportBatch;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentAdvance;
use App\Entity\Tenant\Term;
use App\Service\Import\TextEncoding;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The bursary's opening-balance import: CSV → preview → posted payments.
 *
 * The file states what the school has already collected by hand. Edus computes
 * the bill from the fee schedule, posts the difference as a real `Payment`, and
 * leaves the unpaid remainder visible on the family statement. Nothing is
 * written on the preview pass, and a whole file is never lost because one row is
 * wrong — bad rows are reported with their row number and the rest posts.
 */
final class FeeImportService
{
    private const REQUIRED_COLUMNS = [
        'admission_number',
        'surname',
        'first_name',
        'class_name',
        'student_type',
        'amount_paid',
    ];

    /** Normalised header → accepted spellings. */
    private const COLUMN_ALIASES = [
        'admission_number' => ['admission_number', 'admission_no', 'adm_no', 'admission', 'adm'],
        'surname' => ['surname', 'last_name', 'lastname'],
        'first_name' => ['first_name', 'firstname', 'given_name'],
        'class_name' => ['class_name', 'class', 'classroom', 'classroom_name'],
        'student_type' => ['student_type', 'studenttype', 'type', 'new_or_returning'],
        'amount_paid' => ['amount_paid', 'amountpaid', 'paid', 'amount'],
        'payment_date' => ['payment_date', 'paymentdate', 'date_paid', 'date'],
        'payment_method' => ['payment_method', 'paymentmethod', 'method'],
        'payment_reference' => ['payment_reference', 'paymentreference', 'reference', 'teller_no', 'receipt_no'],
        'note' => ['note', 'notes', 'remark', 'remarks', 'comment'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TuitionInvoiceBuilder $invoices,
        private readonly PaymentReferenceGenerator $paymentReferences,
        private readonly AdvancePaymentService $advances,
    ) {
    }

    /**
     * Parse and normalise the CSV. No pupil is matched and nothing is written.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, repaired: int}
     */
    public function parse(string $path, Term $term): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['rows' => [], 'errors' => ['The uploaded file could not be read.'], 'repaired' => 0];
        }

        $rows = [];
        $fileErrors = [];
        $repaired = 0;
        $header = null;
        $map = [];
        $line = 0;

        // Pass the escape explicitly: PHP 8.4 deprecates relying on its default.
        while (($raw = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $line++;

            if ($raw === [null] || (count($raw) === 1 && trim((string) $raw[0]) === '')) {
                continue;
            }

            foreach ($raw as $cell) {
                if (TextEncoding::neededRepair((string) $cell)) {
                    ++$repaired;
                }
            }

            $raw = TextEncoding::row($raw);

            if ($header === null) {
                $headers = array_map([$this, 'normaliseColumn'], $raw);
                $header = $headers;
                $map = $this->mapColumns($headers);

                $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($map)));
                if ($missing !== []) {
                    fclose($handle);

                    return [
                        'rows' => [],
                        'errors' => [sprintf(
                            'The file is missing the %s column%s. Download the template and keep its headings.',
                            implode(', ', array_map([$this, 'columnLabel'], $missing)),
                            count($missing) === 1 ? '' : 's'
                        )],
                        'repaired' => $repaired,
                    ];
                }

                continue;
            }

            $data = [];
            foreach ($map as $canonical => $index) {
                $data[$canonical] = trim((string) ($raw[$index] ?? ''));
            }

            if (implode('', $data) === '') {
                continue;
            }

            $row = $this->buildRow($line, $data);
            $rows[] = $row;
        }

        fclose($handle);

        if ($header === null) {
            return ['rows' => [], 'errors' => ['The file is empty.'], 'repaired' => $repaired];
        }

        return ['rows' => $rows, 'errors' => $fileErrors, 'repaired' => $repaired];
    }

    /**
     * Match every row to a pupil and price it against the term's fee schedule.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{rows: list<array<string, mixed>>, valid_count: int, error_count: int, warning_count: int, total_due: float, total_paid: float, outstanding: float, repaired: int}
     */
    public function preview(array $rows, Term $term): array
    {
        $classrooms = [];
        foreach ($this->em->getRepository(Classroom::class)->findAll() as $classroom) {
            $classrooms[$this->normaliseClass($classroom->getName())] = $classroom;
        }

        $seenAdmissions = [];
        $validCount = 0;
        $warningCount = 0;
        $totalDue = 0.0;
        $totalPaid = 0.0;
        $outstanding = 0.0;

        foreach ($rows as $index => $row) {
            $row += [
                'student_id' => null,
                'student_name' => null,
                'classroom_id' => null,
                'invoice_id' => null,
                'invoice_number' => null,
                'current_type' => null,
                'due' => 0.0,
                'discount' => 0.0,
                'discount_label' => null,
                'scholarship' => null,
                'outstanding' => 0.0,
                'overpayment' => 0.0,
                'warnings' => [],
                'posted' => false,
                'payment_amount' => 0.0,
            ];

            $admission = (string) $row['admission_number'];

            if ($admission !== '') {
                if (isset($seenAdmissions[$admission])) {
                    $row['errors'][] = sprintf(
                        'Row %d and row %d both name %s.',
                        $seenAdmissions[$admission],
                        $row['row'],
                        $admission
                    );
                } else {
                    $seenAdmissions[$admission] = $row['row'];
                }
            }

            if ($row['errors'] === []) {
                $classroom = $classrooms[$this->normaliseClass((string) $row['class_name'])] ?? null;

                if ($classroom === null) {
                    $row['errors'][] = sprintf(
                        'Row %d: class “%s” was not found. Check the spelling against the class list.',
                        $row['row'],
                        $row['class_name']
                    );
                } else {
                    $row['classroom_id'] = $classroom->getId();
                    $match = $this->matchStudent($row, $classroom, $term);

                    if ($match['error'] !== null) {
                        $row['errors'][] = $match['error'];
                    } else {
                        /** @var Student $student */
                        $student = $match['student'];
                        $row['student_id'] = $student->getId();
                        $row['student_name'] = $student->getFullName();
                        $row['current_type'] = $student->getStudentType();
                        $row['warnings'] = array_merge($row['warnings'], $match['warnings']);

                        $actualClassroom = $this->studentClassroom($student, $term);

                        if ($actualClassroom !== null
                            && $this->normaliseClass($actualClassroom->getName()) !== $this->normaliseClass($classroom->getName())) {
                            $row['errors'][] = sprintf(
                                'Row %d: %s is in %s, but the file says %s.',
                                $row['row'],
                                $student->getFullName(),
                                $actualClassroom->getName(),
                                $classroom->getName()
                            );
                        } else {
                            $this->priceRow($row, $student, $classroom, $term);
                        }

                        if ($row['current_type'] !== $row['student_type']) {
                            $row['warnings'][] = sprintf(
                                'Row %d: the pupil record says %s, the file says %s — the record will be corrected.',
                                $row['row'],
                                $row['current_type'],
                                $row['student_type']
                            );
                        }
                    }
                }
            }

            $row['outstanding'] = round(max(0.0, (float) $row['due'] - (float) $row['amount_paid']), 2);
            $row['overpayment'] = round(max(0.0, (float) $row['amount_paid'] - (float) $row['due']), 2);

            if ($row['warnings'] !== []) {
                ++$warningCount;
            }

            if ($row['errors'] === []) {
                ++$validCount;
                $totalDue += (float) $row['due'];
                $totalPaid += (float) $row['amount_paid'];
                $outstanding += (float) $row['outstanding'];
            }

            $rows[$index] = $row;
        }

        return [
            'rows' => $rows,
            'valid_count' => $validCount,
            'error_count' => count($rows) - $validCount,
            'warning_count' => $warningCount,
            'total_due' => round($totalDue, 2),
            'total_paid' => round($totalPaid, 2),
            'outstanding' => round($outstanding, 2),
            'repaired' => 0,
        ];
    }

    /**
     * Store a preview, reusing an earlier preview of the exact same file.
     *
     * @param array{rows: list<array<string, mixed>>, valid_count: int, error_count: int, warning_count: int, total_due: float, total_paid: float, outstanding: float, repaired: int} $preview
     *
     * @return array{batch: FeeImportBatch, reused: bool, already_posted: bool}
     */
    public function createBatch(
        Term $term,
        string $checksum,
        ?string $filename,
        string $actor,
        array $preview,
    ): array {
        // Latest first: a reversed batch keeps the same checksum, and re-uploading
        // the corrected file must find the current preview, not an old reversal.
        $existing = $this->em->getRepository(FeeImportBatch::class)->createQueryBuilder('b')
            ->andWhere('b.term = :term')->setParameter('term', $term)
            ->andWhere('b.fileChecksum = :checksum')->setParameter('checksum', $checksum)
            ->orderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing instanceof FeeImportBatch) {
            if ($existing->isPosted()) {
                return ['batch' => $existing, 'reused' => true, 'already_posted' => true];
            }

            if ($existing->isPreview()) {
                $this->applyPreview($existing, $preview);
                $this->em->flush();

                return ['batch' => $existing, 'reused' => true, 'already_posted' => false];
            }
        }

        $batch = new FeeImportBatch();
        $batch->setTerm($term)
            ->setFileChecksum($checksum)
            ->setOriginalFilename($filename)
            ->setUploadedBy($actor);

        $this->applyPreview($batch, $preview);
        $this->em->persist($batch);
        $this->em->flush();

        return ['batch' => $batch, 'reused' => false, 'already_posted' => false];
    }

    /**
     * Post every valid row in one transaction.
     *
     * @return array{posted: int, skipped: int, total_due: float, total_paid: float, outstanding: float, error: ?string}
     */
    public function commit(FeeImportBatch $batch, string $actor): array
    {
        if (!$batch->isPreview()) {
            return $this->result(0, 0, 0.0, 0.0, 0.0, 'This batch has already been posted or reversed.');
        }

        $term = $batch->getTerm();
        $other = $this->postedBatchForTerm($term, $batch);

        if ($other !== null) {
            return $this->result(0, 0, 0.0, 0.0, 0.0, sprintf(
                'An opening-balance import for %s was already posted (batch #%d). Reverse that batch first.',
                $term?->getName() ?? 'this term',
                $other->getId()
            ));
        }

        return $this->em->wrapInTransaction(function () use ($batch, $term, $actor): array {
            $rows = $batch->getPayload();
            $posted = 0;
            $skipped = 0;
            $totalDue = 0.0;
            $totalPaid = 0.0;
            $outstanding = 0.0;

            foreach ($rows as $index => $row) {
                if (($row['errors'] ?? []) !== []) {
                    ++$skipped;
                    continue;
                }

                $student = $this->em->find(Student::class, (int) ($row['student_id'] ?? 0));
                $classroom = $this->em->find(Classroom::class, (int) ($row['classroom_id'] ?? 0));

                if ($student === null || $classroom === null) {
                    $rows[$index]['errors'][] = 'The pupil or class no longer exists.';
                    ++$skipped;
                    continue;
                }

                $previousType = $student->getStudentType();
                $student->setStudentType((string) $row['student_type']);

                $invoice = $this->invoices->build($student, $classroom, $term, $student->getStudentType(), true);

                if ($invoice === null) {
                    $student->setStudentType($previousType);
                    $rows[$index]['errors'][] = sprintf(
                        'No fees are set for %s this term. Set the fee schedule first.',
                        $classroom->getName()
                    );
                    ++$skipped;
                    continue;
                }

                $total = (float) $invoice->getTotalAmount();
                $currentPaid = (float) $invoice->getPaidAmount();
                $targetPaid = (float) $row['amount_paid'];

                if ($targetPaid + 0.005 < $currentPaid) {
                    $student->setStudentType($previousType);
                    $rows[$index]['errors'][] = sprintf(
                        '%s already has %s recorded against this term, which is more than the file says (%s). '
                        . 'Nothing was posted for this row — reverse the earlier payment or import first.',
                        $student->getFullName(),
                        number_format($currentPaid, 2),
                        number_format($targetPaid, 2)
                    );
                    ++$skipped;
                    continue;
                }

                $targetInvoicePaid = min($targetPaid, $total);
                $row['payment_amount'] = 0.0;

                if ($targetInvoicePaid > $currentPaid + 0.005) {
                    $amount = round($targetInvoicePaid - $currentPaid, 2);
                    $payment = new Payment();
                    $payment->setInvoice($invoice)
                        ->setAmount(number_format($amount, 2, '.', ''))
                        ->setMethod((string) $row['payment_method'])
                        ->setReferenceCode($this->paymentReferences->next())
                        ->setReference($row['payment_reference'] !== '' ? (string) $row['payment_reference'] : null)
                        ->setStatus(Payment::STATUS_CONFIRMED)
                        ->setConfirmedAt($this->paymentDateTime($row))
                        ->setConfirmedBy($actor)
                        ->setNote($this->paymentNote($batch, $row))
                        ->setImportBatch($batch);

                    $this->em->persist($payment);
                    $invoice->setPaidAmount(number_format($targetInvoicePaid, 2, '.', ''));
                    $row['payment_amount'] = $amount;
                    $row['payment_reference_code'] = $payment->getReferenceCode();
                }

                $isFullyPaid = $targetInvoicePaid >= $total - 0.01;
                $invoice->setStatus(match (true) {
                    $isFullyPaid => Invoice::STATUS_PAID,
                    $targetInvoicePaid > 0.005 => Invoice::STATUS_PARTIAL,
                    default => Invoice::STATUS_UNPAID,
                });

                $overpayment = round(max(0.0, $targetPaid - $total), 2);

                if ($overpayment > 0.005) {
                    $reference = sprintf('IMP-%d-%d', $batch->getId(), $student->getId());
                    $advance = $this->em->getRepository(StudentAdvance::class)->findOneBy(['reference' => $reference]);

                    if ($advance === null) {
                        $advance = new StudentAdvance();
                        $advance->setStudent($student)
                            ->setAmount(number_format($overpayment, 2, '.', ''))
                            ->setReference($reference)
                            ->setMethod((string) $row['payment_method'])
                            ->setReceivedOn($this->paymentDate($row))
                            ->setRemark(sprintf(
                                'Opening balance import batch #%d — overpayment above the term bill',
                                $batch->getId()
                            ))
                            ->setCoversNote('Above the term bill')
                            ->setCreatedBy($actor);
                        $this->em->persist($advance);
                    }
                }

                $row['invoice_id'] = $invoice->getId();
                $row['invoice_number'] = $invoice->getInvoiceNumber();
                $row['previous_type'] = $previousType;
                $row['due'] = $total;
                $row['outstanding'] = round(max(0.0, $total - $targetPaid), 2);
                $row['overpayment'] = $overpayment;
                $row['posted'] = true;
                $rows[$index] = $row;

                $totalDue += $total;
                $totalPaid += $targetPaid;
                $outstanding += $row['outstanding'];
                ++$posted;
            }

            $batch->setPayload($rows)
                ->setPostedCount($posted)
                ->setSkippedCount($skipped)
                ->setTotalDue(number_format($totalDue, 2, '.', ''))
                ->setTotalPaid(number_format($totalPaid, 2, '.', ''))
                ->setOutstanding(number_format($outstanding, 2, '.', ''))
                ->setStatus(FeeImportBatch::STATUS_POSTED)
                ->setPostedAt(new \DateTimeImmutable());

            $this->em->flush();

            return $this->result($posted, $skipped, $totalDue, $totalPaid, $outstanding, null);
        });
    }

    /**
     * Undo a posted batch: remove its payments and the credits it created.
     *
     * @return array{payments: int, advances: int, error: ?string}
     */
    public function reverse(FeeImportBatch $batch, string $actor): array
    {
        if (!$batch->isPosted()) {
            return ['payments' => 0, 'advances' => 0, 'error' => 'Only a posted batch can be reversed.'];
        }

        return $this->em->wrapInTransaction(function () use ($batch, $actor): array {
            $removedPayments = 0;

            foreach ($this->em->getRepository(Payment::class)->findBy(['importBatch' => $batch]) as $payment) {
                $invoice = $payment->getInvoice();

                if ($invoice instanceof Invoice) {
                    $newPaid = max(0.0, (float) $invoice->getPaidAmount() - (float) $payment->getAmount());
                    $total = (float) $invoice->getTotalAmount();
                    $invoice->setPaidAmount(number_format($newPaid, 2, '.', ''));
                    $invoice->setStatus(match (true) {
                        $newPaid >= $total - 0.01 => Invoice::STATUS_PAID,
                        $newPaid > 0.005 => Invoice::STATUS_PARTIAL,
                        default => Invoice::STATUS_UNPAID,
                    });
                }

                $this->em->remove($payment);
                ++$removedPayments;
            }

            $removedAdvances = 0;
            $advances = $this->em->getRepository(StudentAdvance::class)->createQueryBuilder('a')
                ->andWhere('a.reference LIKE :reference')
                ->setParameter('reference', 'IMP-' . $batch->getId() . '-%')
                ->getQuery()
                ->getResult();

            foreach ($advances as $advance) {
                if ($this->advances->balance($advance) < (float) $advance->getAmount() - 0.005) {
                    throw new \RuntimeException(sprintf(
                        'The overpayment credit %s has already been used, so batch #%d cannot be reversed safely. '
                        . 'Reverse the payments that used it first.',
                        $advance->getReference(),
                        $batch->getId()
                    ));
                }

                $this->em->remove($advance);
                ++$removedAdvances;
            }

            $rows = $batch->getPayload();

            foreach ($rows as $index => $row) {
                if (!isset($row['previous_type'], $row['student_type'], $row['student_id'])) {
                    continue;
                }

                $student = $this->em->find(Student::class, (int) $row['student_id']);

                if ($student !== null && $student->getStudentType() === $row['student_type']) {
                    $student->setStudentType((string) $row['previous_type']);
                }

                $rows[$index]['posted'] = false;
            }

            $batch->setPayload($rows)
                ->setStatus(FeeImportBatch::STATUS_ROLLED_BACK)
                ->setRolledBackAt(new \DateTimeImmutable())
                ->setRolledBackBy($actor);

            $this->em->flush();

            return ['payments' => $removedPayments, 'advances' => $removedAdvances, 'error' => null];
        });
    }

    public function postedBatchForTerm(Term $term, ?FeeImportBatch $exclude = null): ?FeeImportBatch
    {
        $qb = $this->em->getRepository(FeeImportBatch::class)->createQueryBuilder('b')
            ->andWhere('b.term = :term')->setParameter('term', $term)
            ->andWhere('b.status = :status')->setParameter('status', FeeImportBatch::STATUS_POSTED)
            ->setMaxResults(1);

        if ($exclude?->getId() !== null) {
            $qb->andWhere('b.id != :id')->setParameter('id', $exclude->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** @param array<string, mixed> $row */
    private function priceRow(array &$row, Student $student, Classroom $classroom, Term $term): void
    {
        $existing = $this->em->getRepository(Invoice::class)->findOneBy([
            'student' => $student,
            'term' => $term,
            'type' => Invoice::TYPE_ACADEMIC,
        ]);

        if ($existing instanceof Invoice && !$this->invoiceIsUnpaid($existing)) {
            $row['invoice_id'] = $existing->getId();
            $row['invoice_number'] = $existing->getInvoiceNumber();
            $row['due'] = (float) $existing->getTotalAmount();
            $row['discount'] = (float) $existing->getDiscountApplied();
            $row['discount_label'] = $existing->getDiscountLabel();
            $row['scholarship'] = $existing->getDiscountLabel();

            return;
        }

        $quote = $this->invoices->quote($student, $classroom, $term, (string) $row['student_type']);

        if ($quote['lines'] === [] && $existing instanceof Invoice) {
            // An unpaid invoice exists but the schedule is currently empty.
            // Show the bill that is actually on file rather than inventing one.
            $row['invoice_id'] = $existing->getId();
            $row['invoice_number'] = $existing->getInvoiceNumber();
            $row['due'] = (float) $existing->getTotalAmount();
            $row['discount'] = (float) $existing->getDiscountApplied();
            $row['discount_label'] = $existing->getDiscountLabel();
            $row['scholarship'] = $existing->getDiscountLabel();

            return;
        }

        if ($quote['lines'] === []) {
            $row['errors'][] = sprintf(
                'Row %d: no fees are set for %s this term. Set the fee schedule first.',
                $row['row'],
                $classroom->getName()
            );

            return;
        }

        $row['invoice_id'] = $existing?->getId();
        $row['invoice_number'] = $existing?->getInvoiceNumber();
        $row['due'] = (float) $quote['total'];
        $row['discount'] = (float) $quote['discount'];
        $row['discount_label'] = $quote['discountLabel'];
        $row['scholarship'] = $quote['discountLabel'];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{student: ?Student, warnings: list<string>, error: ?string}
     */
    private function matchStudent(array $row, Classroom $classroom, Term $term): array
    {
        $admission = (string) $row['admission_number'];
        $student = $this->em->getRepository(Student::class)->findOneBy(['admissionNumber' => $admission]);

        if ($student instanceof Student) {
            if (!$this->sameName($student->getLastName(), (string) $row['surname'])
                || !$this->sameName($student->getFirstName(), (string) $row['first_name'])) {
                return [
                    'student' => null,
                    'warnings' => [],
                    'error' => sprintf(
                        'Row %d: %s belongs to %s, not %s %s. Check the row order.',
                        $row['row'],
                        $admission,
                        $student->getFullName(),
                        $row['first_name'],
                        $row['surname']
                    ),
                ];
            }

            return ['student' => $student, 'warnings' => [], 'error' => null];
        }

        $matches = $this->em->getRepository(Student::class)->createQueryBuilder('s')
            ->andWhere('LOWER(s.lastName) = :surname')
            ->andWhere('LOWER(s.firstName) = :firstName')
            ->setParameter('surname', mb_strtolower((string) $row['surname']))
            ->setParameter('firstName', mb_strtolower((string) $row['first_name']))
            ->getQuery()
            ->getResult();

        $matches = array_values(array_filter(
            $matches,
            fn (Student $candidate): bool => $this->normaliseClass(
                $this->studentClassroom($candidate, $term)?->getName()
            ) === $this->normaliseClass($classroom->getName())
        ));

        if (count($matches) === 1) {
            return [
                'student' => $matches[0],
                'warnings' => [sprintf(
                    'Row %d: admission number %s was not found; matched by name to %s (%s).',
                    $row['row'],
                    $admission,
                    $matches[0]->getFullName(),
                    $matches[0]->getAdmissionNumber() ?? 'no number'
                )],
                'error' => null,
            ];
        }

        if (count($matches) > 1) {
            return [
                'student' => null,
                'warnings' => [],
                'error' => sprintf(
                    'Row %d: %s is not on roll, and %s %s in %s matches more than one pupil (%s). Use the admission number.',
                    $row['row'],
                    $admission,
                    $row['first_name'],
                    $row['surname'],
                    $classroom->getName(),
                    implode(', ', array_map(static fn (Student $s): string => $s->getFullName(), $matches))
                ),
            ];
        }

        return [
            'student' => null,
            'warnings' => [],
            'error' => sprintf('Row %d: %s is not on roll. Check the number.', $row['row'], $admission),
        ];
    }

    private function studentClassroom(Student $student, Term $term): ?Classroom
    {
        $classroom = $student->getCurrentClass();

        if ($classroom !== null) {
            return $classroom;
        }

        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(
            ['student' => $student, 'session' => $term->getSession()],
            ['id' => 'DESC']
        );

        return $enrollment?->getClassroom();
    }

    private function invoiceIsUnpaid(Invoice $invoice): bool
    {
        if ((float) $invoice->getPaidAmount() > 0.005) {
            return false;
        }

        foreach ($invoice->getPayments() as $payment) {
            if ($payment->getStatus() === Payment::STATUS_CONFIRMED) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $data */
    private function buildRow(int $rowNumber, array $data): array
    {
        $row = [
            'row' => $rowNumber,
            'admission_number' => $data['admission_number'] ?? '',
            'surname' => $data['surname'] ?? '',
            'first_name' => $data['first_name'] ?? '',
            'class_name' => $data['class_name'] ?? '',
            'student_type' => '',
            'amount_paid' => 0.0,
            'payment_date' => null,
            'payment_method' => Payment::METHOD_CASH,
            'payment_reference' => $data['payment_reference'] ?? '',
            'note' => $data['note'] ?? '',
            'errors' => [],
        ];

        foreach (['admission_number', 'surname', 'first_name', 'class_name'] as $required) {
            if (trim((string) $row[$required]) === '') {
                $row['errors'][] = sprintf('Row %d: %s is required.', $rowNumber, $this->columnLabel($required));
            }
        }

        $type = $this->parseStudentType((string) ($data['student_type'] ?? ''));
        if ($type === null) {
            $row['errors'][] = sprintf(
                'Row %d: student type “%s” is not NEW or RETURNING.',
                $rowNumber,
                trim((string) ($data['student_type'] ?? ''))
            );
        } else {
            $row['student_type'] = $type;
        }

        $amount = $this->parseAmount((string) ($data['amount_paid'] ?? ''));
        if ($amount['error'] !== null) {
            $row['errors'][] = sprintf('Row %d: %s', $rowNumber, $amount['error']);
        } else {
            $row['amount_paid'] = $amount['value'];
        }

        if (trim((string) ($data['payment_date'] ?? '')) !== '') {
            $date = $this->parseDate((string) $data['payment_date']);
            if ($date === null) {
                $row['errors'][] = sprintf(
                    'Row %d: payment date “%s” is not a date. Use YYYY-MM-DD.',
                    $rowNumber,
                    $data['payment_date']
                );
            } else {
                $row['payment_date'] = $date->format('Y-m-d');
            }
        }

        $method = $this->parseMethod((string) ($data['payment_method'] ?? ''));
        if ($method === null) {
            $row['errors'][] = sprintf(
                'Row %d: payment method “%s” is not CASH, TRANSFER, POS or CHEQUE.',
                $rowNumber,
                $data['payment_method']
            );
        } else {
            $row['payment_method'] = $method;
        }

        return $row;
    }

    /** @return array{value: float, error: ?string} */
    private function parseAmount(string $value): array
    {
        $value = str_replace(['₦', ' '], '', trim($value));

        if ($value === '') {
            return ['value' => 0.0, 'error' => null];
        }

        if (str_starts_with($value, '-')) {
            return ['value' => 0.0, 'error' => 'amount paid cannot be negative.'];
        }

        if (str_contains($value, ',')) {
            return ['value' => 0.0, 'error' => sprintf('amount paid “%s” has a thousands separator. Write 25000.', $value)];
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            return ['value' => 0.0, 'error' => sprintf('amount paid “%s” is not a number. Write 25000 or 25000.00.', $value)];
        }

        return ['value' => round((float) $value, 2), 'error' => null];
    }

    private function parseStudentType(string $value): ?string
    {
        $value = strtoupper(trim($value));

        return match ($value) {
            'NEW', 'NEW INTAKE', 'NEW_INTAKE', 'NEWINTAKE' => Student::TYPE_NEW,
            'RETURNING', 'RETURN', 'OLD', 'EXISTING' => Student::TYPE_RETURNING,
            default => null,
        };
    }

    private function parseMethod(string $value): ?string
    {
        $value = strtoupper(trim($value));

        if ($value === '') {
            return Payment::METHOD_CASH;
        }

        return match ($value) {
            'CASH', 'CASH PAYMENT' => Payment::METHOD_CASH,
            'TRANSFER', 'BANK TRANSFER', 'BANK' => Payment::METHOD_TRANSFER,
            'POS', 'CARD' => Payment::METHOD_POS,
            'CHEQUE', 'CHECK' => Payment::METHOD_CHEQUE,
            default => null,
        };
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'j/n/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, trim($value));
            $errors = \DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function paymentDate(array $row): \DateTimeImmutable
    {
        if (!empty($row['payment_date'])) {
            $date = $this->parseDate((string) $row['payment_date']);

            if ($date !== null) {
                return $date;
            }
        }

        return new \DateTimeImmutable('today');
    }

    /** @param array<string, mixed> $row */
    private function paymentDateTime(array $row): \DateTimeImmutable
    {
        return $this->paymentDate($row)->setTime(12, 0);
    }

    /** @param array<string, mixed> $row */
    private function paymentNote(FeeImportBatch $batch, array $row): string
    {
        $note = sprintf('Opening balance import batch #%d', (int) $batch->getId());

        if (trim((string) ($row['note'] ?? '')) !== '') {
            $note .= ' — ' . trim((string) $row['note']);
        }

        return $note;
    }

    /** @param array{rows: list<array<string, mixed>>, valid_count: int, error_count: int, warning_count: int, total_due: float, total_paid: float, outstanding: float, repaired: int} $preview */
    private function applyPreview(FeeImportBatch $batch, array $preview): void
    {
        $batch->setPayload($preview['rows'])
            ->setRowCount(count($preview['rows']))
            ->setPostedCount(0)
            ->setSkippedCount($preview['error_count'])
            ->setTotalDue(number_format($preview['total_due'], 2, '.', ''))
            ->setTotalPaid(number_format($preview['total_paid'], 2, '.', ''))
            ->setOutstanding(number_format($preview['outstanding'], 2, '.', ''));
    }

    /** @return array{posted: int, skipped: int, total_due: float, total_paid: float, outstanding: float, error: ?string} */
    private function result(int $posted, int $skipped, float $due, float $paid, float $outstanding, ?string $error): array
    {
        return [
            'posted' => $posted,
            'skipped' => $skipped,
            'total_due' => round($due, 2),
            'total_paid' => round($paid, 2),
            'outstanding' => round($outstanding, 2),
            'error' => $error,
        ];
    }

    private function normaliseColumn(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
    }

    /** @param list<string> $headers */
    private function mapColumns(array $headers): array
    {
        $map = [];

        foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
            foreach ($headers as $index => $header) {
                if (in_array($header, $aliases, true)) {
                    $map[$canonical] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function columnLabel(string $column): string
    {
        return match ($column) {
            'admission_number' => 'Admission Number',
            'surname' => 'Surname',
            'first_name' => 'First Name',
            'class_name' => 'Class Name',
            'student_type' => 'Student Type',
            'amount_paid' => 'Amount Paid',
            default => str_replace('_', ' ', ucfirst($column)),
        };
    }

    private function sameName(?string $left, ?string $right): bool
    {
        $left = $this->normaliseName($left);
        $right = $this->normaliseName($right);

        return $left !== '' && $left === $right;
    }

    private function normaliseName(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }

    private function normaliseClass(?string $value): string
    {
        $value = str_replace(['.', '-', '_', '/', ' '], '', (string) $value);

        return mb_strtolower($value);
    }
}
