<?php

namespace App\Service;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Every notification email a school sends — to a parent, a guardian, or its
 * own admin users (term summaries and the weekly fee position).
 *
 * Two rules this class exists to enforce:
 *
 *  1. **The school decides.** Nothing is sent unless `School::isEmailEnabledFor()`
 *     says so — that checks the master switch AND the per-event switch, so a
 *     school that is wary of sending costs can turn any trigger off.
 *
 *  2. **A mail outage is never a data-loss event.** Every send is wrapped; a
 *     failure is logged and reported as `false`. Callers carry on. (See
 *     docs/operations/EMAIL-SETUP.md §6.)
 *
 * The parent-facing sign-in / verification mail lives in `ApplicationMailer`.
 */
final class SchoolMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from = 'Edus <no-reply@edus.ng>',
        #[Autowire('%env(MAILER_REPLY_TO)%')]
        private string $replyTo = 'support@edus.ng',
    ) {
    }

    // ─────────────────────────────────────────────────────────────────
    // Triggers — one method per message
    // ─────────────────────────────────────────────────────────────────

    /** "We have your application" — the parent's proof of submission. */
    public function applicationSubmitted(
        School $school,
        Application $application,
        ?string $url = null,
        ?float $feeDue = null,
    ): bool {
        $guardian = $application->getGuardian();
        $child = trim(sprintf('%s %s', $application->getFirstName(), $application->getLastName()));

        return $this->send(
            school: $school,
            event: School::EMAIL_APPLICATION_SUBMITTED,
            toEmail: $guardian?->getEmail() ?? $application->getGuardianEmail(),
            toName: $guardian?->getFullName() ?? $application->getGuardianName(),
            subject: 'We received your application',
            headline: 'Application received',
            intro: sprintf(
                'Thank you. %s\'s application has reached %s and is now in the queue for review.',
                $child !== '' ? $child : 'Your child\'s',
                $school->getName()
            ),
            template: 'emails/parent/application_submitted.html.twig',
            context: [
                'child_name' => $child,
                'reference' => $application->getReference(),
                'submitted_at' => $application->getSubmittedAt(),
                'requested_class' => $application->getRequestedClassName(),
                'status' => $application->getStatus(),
                'fee_due' => $feeDue,
                'fee_due_formatted' => ($feeDue !== null && $feeDue > 0) ? $this->money($feeDue) : null,
            ],
            buttonLabel: $url ? 'Track my application' : null,
            url: $url,
        );
    }

    /**
     * The admission verdict. `$approved` picks the copy, so one method covers
     * "you have a place" and "not this time" — and both are logged the same way.
     *
     * @param array<string, mixed> $extra
     */
    public function admissionDecision(
        School $school,
        Student $student,
        bool $approved,
        ?string $url = null,
        ?string $note = null,
        array $extra = [],
    ): bool {
        $guardian = $student->getGuardian();
        $name = $student->getFullName();

        if ($approved) {
            $subject = 'Congratulations — a place has been offered';
            $headline = 'Your child has been offered a place';
            $intro = sprintf(
                'We are pleased to offer %s a place at %s. Welcome to the family.',
                $name,
                $school->getName()
            );
            $button = $url ? 'Open the parent portal' : null;
        } else {
            $subject = 'About your child\'s application';
            $headline = 'Application outcome';
            $intro = sprintf(
                'Thank you for considering %s for %s. After careful review we are unable to offer a place on this occasion.',
                $school->getName(),
                $name
            );
            $button = null;
        }

        return $this->send(
            school: $school,
            event: School::EMAIL_ADMISSION_DECISION,
            toEmail: $guardian?->getEmail(),
            toName: $guardian?->getFullName(),
            subject: $subject,
            headline: $headline,
            intro: $intro,
            template: 'emails/parent/admission_decision.html.twig',
            context: array_merge([
                'approved' => $approved,
                'child_name' => $name,
                'admission_number' => $student->getAdmissionNumber(),
                'classroom' => $student->getCurrentClass()?->getName(),
                'note' => $note,
            ], $extra),
            buttonLabel: $button,
            url: $url,
        );
    }

    /** A bill has been raised — with the amount and what is left to pay. */
    public function invoiceIssued(School $school, Invoice $invoice, ?string $url = null): bool
    {
        $student = $invoice->getStudent();
        $guardian = $student?->getGuardian();

        return $this->send(
            school: $school,
            event: School::EMAIL_INVOICE_ISSUED,
            toEmail: $guardian?->getEmail(),
            toName: $guardian?->getFullName(),
            subject: sprintf('New invoice %s', (string) $invoice->getInvoiceNumber()),
            headline: 'A new invoice has been raised',
            intro: sprintf(
                'A %s invoice has been issued for %s. The full breakdown is below.',
                $invoice->getType() === 'STORE' ? 'store' : 'school fees',
                $student?->getFullName() ?? 'your child'
            ),
            template: 'emails/parent/invoice_issued.html.twig',
            context: [
                'invoice_number' => $invoice->getInvoiceNumber(),
                'invoice_type' => $invoice->getType(),
                'child_name' => $student?->getFullName(),
                'total_amount' => (float) $invoice->getTotalAmount(),
                'paid_amount' => (float) $invoice->getPaidAmount(),
                'balance_due' => $invoice->getBalanceDue(),
                'status' => $invoice->getStatus(),
                'session_name' => $invoice->getSession()?->getName(),
                'term_name' => $invoice->getTerm()?->getName(),
                'created_at' => $invoice->getCreatedAt(),
                'items' => $this->invoiceItems($invoice),
                'bank' => [
                    'name' => $school->getBankName(),
                    'account_number' => $school->getAccountNumber(),
                    'account_name' => $school->getAccountName(),
                ],
            ],
            buttonLabel: $url ? 'View and pay' : null,
            url: $url,
        );
    }

    /** Money received — the receipt a parent can keep. */
    public function paymentReceipt(School $school, Payment $payment, ?string $url = null): bool
    {
        $invoice = $payment->getInvoice();
        $student = $invoice?->getStudent();
        $guardian = $student?->getGuardian();

        return $this->send(
            school: $school,
            event: School::EMAIL_PAYMENT_RECEIPT,
            toEmail: $guardian?->getEmail(),
            toName: $guardian?->getFullName(),
            subject: sprintf('Payment received — %s', (string) $payment->getReferenceCode()),
            headline: 'Thank you — payment received',
            intro: sprintf(
                'We have received %s for %s. This email is your receipt.',
                $this->money((float) $payment->getAmount()),
                $student?->getFullName() ?? 'your child'
            ),
            template: 'emails/parent/payment_receipt.html.twig',
            context: [
                'reference_code' => $payment->getReferenceCode(),
                'method' => $payment->getMethod(),
                'amount' => (float) $payment->getAmount(),
                'paid_at' => $payment->getConfirmedAt() ?? $payment->getCreatedAt(),
                'child_name' => $student?->getFullName(),
                'invoice_number' => $invoice?->getInvoiceNumber(),
                'invoice_total' => (float) ($invoice?->getTotalAmount() ?? 0),
                'invoice_paid' => (float) ($invoice?->getPaidAmount() ?? 0),
                'balance_due' => $invoice?->getBalanceDue() ?? 0.0,
            ],
            buttonLabel: $url ? 'View my receipts' : null,
            url: $url,
        );
    }

    /** Termly results are ready to view. */
    public function resultPublished(School $school, Student $student, ?Term $term = null, ?string $url = null): bool
    {
        $guardian = $student->getGuardian();

        return $this->send(
            school: $school,
            event: School::EMAIL_RESULT_PUBLISHED,
            toEmail: $guardian?->getEmail(),
            toName: $guardian?->getFullName(),
            subject: sprintf('%s\'s result is ready', $student->getFullName()),
            headline: 'Termly result published',
            intro: sprintf(
                '%s\'s result for %s is now available in the parent portal.',
                $student->getFullName(),
                $term?->getName() ?? 'this term'
            ),
            template: 'emails/parent/result_published.html.twig',
            context: [
                'child_name' => $student->getFullName(),
                'term_name' => $term?->getName(),
                'session_name' => $term?->getSession()?->getName(),
                'admission_number' => $student->getAdmissionNumber(),
                'classroom' => $student->getCurrentClass()?->getName(),
            ],
            buttonLabel: $url ? 'View result' : null,
            url: $url,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // The single delivery path
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $context
     */
    public function send(
        School $school,
        string $event,
        ?string $toEmail,
        ?string $toName,
        string $subject,
        string $headline,
        string $intro,
        string $template,
        array $context = [],
        ?string $buttonLabel = null,
        ?string $url = null,
        array $attachments = [],
    ): bool {
        if (!$school->isEmailEnabledFor($event)) {
            $this->logger->info('SchoolMailer: {event} disabled for school {school}', [
                'event' => $event,
                'school' => $school->getSubdomain() ?? (string) $school->getId(),
            ]);

            return false;
        }

        $toEmail = $toEmail !== null ? trim($toEmail) : '';
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->info('SchoolMailer: {event} skipped — no usable recipient', ['event' => $event]);

            return false;
        }

        try {
            $email = (new TemplatedEmail())
                ->from($this->from)
                // Replies go to the school, not to Edus — that is what a parent expects.
                ->replyTo($school->getEmail() ?: $this->replyTo)
                ->to(new Address($toEmail, (string) ($toName ?? '')))
                ->subject($subject . ' — ' . $school->getName())
                ->htmlTemplate($template)
                ->context($context + [
                    'guardian_name' => $toName,
                    'school_name' => $school->getName(),
                    'school_motto' => $school->getMotto(),
                    'school_phone' => $school->getPhoneNumber(),
                    'school_address' => $school->getAddress(),
                    'school_email' => $school->getEmail(),
                    'brand' => $school->getPrimaryColor() ?: '#1e40af',
                    'headline' => $headline,
                    'intro' => $intro,
                    'button_label' => $buttonLabel,
                    'url' => $url,
                    'subject_line' => $subject,
                ]);

            foreach ($attachments as $attachment) {
                $email->attach(
                    (string) $attachment['content'],
                    (string) $attachment['filename'],
                    (string) ($attachment['mime'] ?? 'application/pdf')
                );
            }

            $this->mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            // Never let a mail outage roll back the caller's work.
            $this->logger->error('SchoolMailer: {event} to {to} failed — {message}', [
                'event' => $event,
                'to' => $toEmail,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<array{name: string, amount: float}>
     */
    private function invoiceItems(Invoice $invoice): array
    {
        $items = [];
        foreach ($invoice->getItems() as $item) {
            $items[] = [
                'name' => (string) $item->getDescription(),
                'amount' => (float) $item->getAmount(),
            ];
        }

        return $items;
    }

    private function money(float $amount): string
    {
        return '₦' . number_format($amount, 2);
    }
}
