<?php

namespace App\Service\Fees;

use App\Entity\Tenant\School;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use App\Service\SchoolMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Phase 4 — the two staff-facing fee emails.
 *
 *   • end-of-term: the R9 PDF attached, sent once per term;
 *   • weekly aging: totals plus the largest family balances.
 *
 * Both go to the school's own `ROLE_ADMIN` users, falling back to the school's
 * public email. Nothing is sent to parents — reminders to guardians remain
 * drafts the bursar sends deliberately.
 */
final class FeeReportNotifier
{
    public function __construct(
        private readonly FeeReportService $reports,
        private readonly FeeReportPdfRenderer $pdfRenderer,
        private readonly SchoolMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{sent: bool, recipients: string[], reason: ?string}
     */
    public function sendTermSummary(Term $term, ?string $overrideRecipient = null, bool $force = false): array
    {
        if ($term->getSummarySentAt() !== null && !$force) {
            return [
                'sent' => false,
                'recipients' => [],
                'reason' => 'Already sent on ' . $term->getSummarySentAt()->format('j M Y'),
            ];
        }

        $school = $this->em->getRepository(School::class)->findOneBy([]);
        if (!$school instanceof School) {
            return ['sent' => false, 'recipients' => [], 'reason' => 'No school settings'];
        }

        $period = ($term->getSession()?->getName() ?? '—') . ' · ' . $term->getName();
        $totals = $this->reports->termTotals($term);
        $statement = $this->reports->incomeStatement($term);

        $context = $this->pdfRenderer->context([
            'term_id'      => $term->getId(),
            'subdomain'    => $school->getSubdomain(),
            'period'       => $period,
            'generated_at' => new \DateTimeImmutable(),
            'totals'       => $totals,
            'statement'    => $statement,
            'classes'      => $this->reports->classCollection($term),
            'debtors'      => array_slice($this->reports->guardianDebtors($term), 0, 8),
        ], $school);

        $pdf = $this->pdfRenderer->render($context);
        $filename = sprintf('fee-summary-term-%s-%s.pdf', $term->getId(), (new \DateTimeImmutable())->format('Y-m-d'));

        $url = $this->urls->generate(
            'app_tenant_report_income',
            ['term' => $term->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $sent = [];
        foreach ($this->recipients($school, $overrideRecipient) as $recipient) {
            $ok = $this->mailer->send(
                $school,
                School::EMAIL_TERM_SUMMARY,
                $recipient['email'],
                $recipient['name'],
                'Term fee summary',
                $period . ' fee summary',
                'Here is the term position: what was expected, what was collected and what is still outstanding.',
                'emails/school/term_summary.html.twig',
                [
                    'period'      => $period,
                    'outstanding' => $totals['outstanding'],
                    'expected'    => $totals['netBillable'],
                    'collected'   => $totals['collected'],
                    'rate'        => $totals['collectionRate'],
                    'pending'     => $totals['pending'],
                    'expenses'    => $statement['expenses']['total'],
                    'net_cash'    => $statement['net']['cash'],
                ],
                'Open fee reports',
                $url,
                [['filename' => $filename, 'content' => $pdf, 'mime' => 'application/pdf']]
            );

            if ($ok) {
                $sent[] = $recipient['email'];
            }
        }

        if ($sent !== []) {
            $term->setSummarySentAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return [
            'sent' => $sent !== [],
            'recipients' => $sent,
            'reason' => $sent === [] ? 'Mailer disabled, or no usable recipient' : null,
        ];
    }

    /**
     * @return array{sent: bool, recipients: string[], reason: ?string}
     */
    public function sendAgingReminder(Term $term, ?string $overrideRecipient = null): array
    {
        $school = $this->em->getRepository(School::class)->findOneBy([]);
        if (!$school instanceof School) {
            return ['sent' => false, 'recipients' => [], 'reason' => 'No school settings'];
        }

        $period = ($term->getSession()?->getName() ?? '—') . ' · ' . $term->getName();
        $totals = $this->reports->termTotals($term);
        $families = $this->reports->guardianDebtors($term);

        $url = $this->urls->generate(
            'app_tenant_report_debtors',
            ['term' => $term->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $sent = [];
        foreach ($this->recipients($school, $overrideRecipient) as $recipient) {
            $ok = $this->mailer->send(
                $school,
                School::EMAIL_AGING_REMINDER,
                $recipient['email'],
                $recipient['name'],
                'Weekly fee position',
                $period . ' — outstanding fees',
                'This is the weekly position. Draft a reminder from the outstanding-fees screen when you are ready.',
                'emails/school/aging_reminder.html.twig',
                [
                    'period'        => $period,
                    'outstanding'   => $totals['outstanding'],
                    'families_count' => count($families),
                    'pending'       => $totals['pending'],
                    'families'      => array_slice($families, 0, 8),
                ],
                'Open outstanding fees',
                $url
            );

            if ($ok) {
                $sent[] = $recipient['email'];
            }
        }

        return [
            'sent' => $sent !== [],
            'recipients' => $sent,
            'reason' => $sent === [] ? 'Mailer disabled, or no usable recipient' : null,
        ];
    }

    /**
     * @return array<int, array{email: string, name: string}>
     */
    private function recipients(School $school, ?string $override): array
    {
        if ($override !== null && trim($override) !== '') {
            return [[
                'email' => trim($override),
                'name'  => $school->getPrincipalName() ?: $school->getName(),
            ]];
        }

        $list = [];
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            if ($user->getEmail() === null) {
                continue;
            }
            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $list[$user->getEmail()] = $user->getFullName() ?: $user->getEmail();
            }
        }

        if ($list === [] && $school->getEmail() !== null) {
            $list[$school->getEmail()] = $school->getName();
        }

        $recipients = [];
        foreach ($list as $email => $name) {
            $recipients[] = ['email' => (string) $email, 'name' => (string) $name];
        }

        return $recipients;
    }
}
