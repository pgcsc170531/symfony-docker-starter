<?php

namespace App\Service\Mail;

use App\Entity\Tenant\School;
use App\Entity\Tenant\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * "Here is the link to choose a new password."
 *
 * Deliberately NOT routed through SchoolMailer: that class is gated on the
 * school's per-event email switches, and a person who cannot sign in must not be
 * blocked because the office turned off application notifications. This mail is
 * transactional and always sends.
 */
final class PasswordResetMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $from = 'Edus <no-reply@edus.ng>',
        #[Autowire('%env(MAILER_REPLY_TO)%')]
        private readonly string $replyTo = 'support@edus.ng',
    ) {
    }

    public function send(User $user, string $url, ?School $school, int $expiresMinutes): void
    {
        $schoolName = $school?->getName() ?? 'your school';

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(new Address((string) $user->getEmail(), (string) $user->getFullName()))
            ->replyTo($this->replyTo)
            ->subject('Choose a new password — ' . $schoolName)
            ->htmlTemplate('emails/account/password_reset.html.twig')
            ->context([
                'person_name'     => $user->getFullName() ?: 'there',
                'school_name'     => $schoolName,
                'school_tag'      => $school?->getTypeTag(),
                'school_motto'    => $school?->getMotto(),
                'school_phone'    => $school?->getPhoneNumber(),
                'school_address'  => $school?->getAddress(),
                'school_email'    => $school?->getEmail(),
                'brand'           => $school?->getPrimaryColor() ?: '#1e40af',
                'url'             => $url,
                'expires_minutes' => $expiresMinutes,
                'subject_line'    => 'Choose a new password',
            ]);

        $this->mailer->send($email);
    }
}
