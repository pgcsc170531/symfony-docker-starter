<?php

namespace App\Service\Results;

use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends the report PDF to the parent who asked for it.
 *
 * This is a transactional, parent-initiated email — the parent pressed the
 * button — so it is not suppressed by the school's marketing toggles. It still
 * needs a configured mailer and a usable guardian/account address, and a mail
 * outage never breaks the page.
 */
final class ResultMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
    ) {
    }

    public function send(
        School $school,
        Student $student,
        Term $term,
        string $pdf,
        string $filename,
        ?string $toEmail,
        ?string $toName,
    ): bool {
        if ($toEmail === null || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        try {
            $email = (new TemplatedEmail())
                ->from($this->from)
                ->replyTo($school->getEmail() ?: $this->from)
                ->to(new Address($toEmail, (string) $toName))
                ->subject(sprintf('%s — %s report attached', $student->getFullName(), $term->getName()))
                ->htmlTemplate('emails/parent/result_attached.html.twig')
                ->context([
                    'guardian_name' => $toName,
                    'child_name' => $student->getFullName(),
                    'term_name' => $term->getName(),
                    'session_name' => $term->getSession()?->getName(),
                    'school_name' => $school->getName(),
                    'school_motto' => $school->getMotto(),
                    'school_phone' => $school->getPhoneNumber(),
                    'school_address' => $school->getAddress(),
                    'school_email' => $school->getEmail(),
                    'brand' => $school->getPrimaryColor() ?: '#1e40af',
                    'subject_line' => sprintf('%s report', $term->getName()),
                    'headline' => 'Your child\'s report is attached',
                    'intro' => sprintf(
                        '%s\'s %s report is attached to this email. Keep it for your records; the secure link in the portal can be revoked at any time.',
                        $student->getFullName(),
                        $term->getName()
                    ),
                ])
                ->attach(new DataPart($pdf, $filename, 'application/pdf'));

            $this->mailer->send($email);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
