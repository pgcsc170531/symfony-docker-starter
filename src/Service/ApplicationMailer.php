<?php

namespace App\Service;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Every email the application module sends.
 *
 * One method per message so the wording + template can be changed in one place,
 * and so a school's branding is always applied. Sending itself stays with
 * symfony/mailer (Brevo SMTP in production, Mailpit in development).
 */
final class ApplicationMailer
{
    /** Confirm the address is really theirs. */
    public const PURPOSE_VERIFY = 'verify';

    /** "The school already has an account for this contact" — sign-in link. */
    public const PURPOSE_RECOVER = 'recover';

    /** Used when a bursar needs to let a parent back in after a decision. */
    public const PURPOSE_REMINDER = 'reminder';

    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from = 'Edus <no-reply@edus.ng>',
        #[Autowire('%env(MAILER_REPLY_TO)%')]
        private string $replyTo = 'support@edus.ng',
    ) {
    }

    /**
     * The single signed link used for BOTH email verification and account
     * recovery — clicking it proves ownership of the inbox and signs the parent
     * in, so we never need a paid SMS OTP.
     */
    public function sendAccessLink(
        School $school,
        Guardian $guardian,
        string $url,
        string $purpose = self::PURPOSE_VERIFY,
        int $expiresMinutes = 120,
    ): void {
        $copy = match ($purpose) {
            self::PURPOSE_RECOVER => [
                'subject'  => 'Sign in to your parent portal',
                'headline' => 'Sign in to your parent portal',
                'intro'    => 'An account already exists for this email address. Use the button below to sign in — no password needed.',
                'button'   => 'Sign in to my account',
            ],
            self::PURPOSE_REMINDER => [
                'subject'  => 'Finish your child\'s application',
                'headline' => 'Continue your application',
                'intro'    => 'You still have an application that is waiting to be completed. Pick up exactly where you stopped.',
                'button'   => 'Continue my application',
            ],
            default => [
                'subject'  => 'Confirm your email address',
                'headline' => 'Welcome to the parent portal',
                'intro'    => 'Thank you for registering. Confirm this email address to activate your parent portal account and start applying for your child.',
                'button'   => 'Confirm my email and continue',
            ],
        };

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(new Address($guardian->getEmail(), (string) $guardian->getFullName()))
            ->replyTo($this->replyTo)
            ->subject($copy['subject'] . ' — ' . $school->getName())
            ->htmlTemplate('emails/parent/access_link.html.twig')
            ->context([
                'guardian_name'   => $guardian->getFullName(),
                'school_name'     => $school->getName(),
                'school_motto'    => $school->getMotto(),
                'school_phone'    => $school->getPhoneNumber(),
                'school_address'  => $school->getAddress(),
                'school_email'    => $school->getEmail(),
                'brand'           => $school->getPrimaryColor() ?: '#1e40af',
                'headline'        => $copy['headline'],
                'intro'           => $copy['intro'],
                'button_label'    => $copy['button'],
                'url'             => $url,
                'expires_minutes' => $expiresMinutes,
                'subject_line'    => $copy['subject'],
            ]);

        $this->mailer->send($email);
    }
}
