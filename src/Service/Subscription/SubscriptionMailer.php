<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\RegistrationInvite;
use App\Entity\Landlord\School;
use App\Entity\Landlord\Subscription;
use App\Entity\Landlord\SubscriptionPayment;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Billing emails the LANDLORD sends to a school. Kept separate from
 * ApplicationMailer (which speaks to parents) so the two audiences never share
 * wording or branding by accident.
 */
final class SubscriptionMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%app.base_domain%')]
        private readonly string $baseDomain,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $from = 'Edus <no-reply@edus.ng>',
        #[Autowire('%env(MAILER_REPLY_TO)%')]
        private readonly string $replyTo = 'support@edus.ng',
    ) {
    }

    /**
     * @return bool false when the school has no usable billing address
     */
    public function sendReminder(SubscriptionPayment $payment): bool
    {
        $school = $payment->getSchool();
        $to = trim((string) $school?->getPrincipalEmail());

        if ($school === null || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $scheme = str_contains($this->baseDomain, 'localhost') ? 'http' : 'https';
        $subdomain = trim((string) $school->getSubdomain());
        $portal = $subdomain === ''
            ? null
            : sprintf('%s://%s.%s/subscription', $scheme, $subdomain, $this->baseDomain);

        $registration = $payment->getTermRegistration();

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(new Address($to, (string) $school->getName()))
            ->replyTo($this->replyTo)
            ->subject(sprintf('Invoice %s for %s is still open', $payment->getReference(), $school->getName()))
            ->htmlTemplate('emails/subscription/reminder.html.twig')
            ->context([
                'school_name' => $school->getName(),
                'reference' => $payment->getReference(),
                'amount' => number_format((float) $payment->getAmount(), 2),
                'status' => (string) $payment->getStatus(),
                'period' => $registration?->getPeriod(),
                'portal_url' => $portal,
            ]);

        $this->mailer->send($email);

        return true;
    }

    /**
     * "Your term is ending" — for a subscription that has no open invoice yet.
     */
    public function sendExpiryReminder(Subscription $subscription): bool
    {
        $school = $subscription->getSchool();
        $to = trim((string) $school?->getPrincipalEmail());

        if ($school === null || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $scheme = str_contains($this->baseDomain, 'localhost') ? 'http' : 'https';
        $subdomain = trim((string) $school->getSubdomain());
        $portal = $subdomain === ''
            ? null
            : sprintf('%s://%s.%s/subscription', $scheme, $subdomain, $this->baseDomain);

        $expired = !$subscription->isValid();
        $endDate = $subscription->getEndDate();

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(new Address($to, (string) $school->getName()))
            ->replyTo($this->replyTo)
            ->subject($expired
                ? sprintf('%s: your Edus subscription has ended', $school->getName())
                : sprintf('%s: your Edus subscription renews soon', $school->getName()))
            ->htmlTemplate('emails/subscription/expiry.html.twig')
            ->context([
                'school_name' => $school->getName(),
                'plan_name' => $subscription->getPlan()?->getName(),
                'end_date' => $endDate?->format('j M Y'),
                'days_left' => $subscription->getDaysRemaining(),
                'expired' => $expired,
                'portal_url' => $portal,
            ]);

        $this->mailer->send($email);

        return true;
    }

    /**
     * The one-click invitation to register a term. The URL carries the token;
     * the school still has to sign in, so a forwarded email is harmless.
     */
    public function sendRegistrationInvite(School $school, string $url): bool
    {
        $to = trim((string) $school->getPrincipalEmail());

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(new Address($to, (string) $school->getName()))
            ->replyTo($this->replyTo)
            ->subject(sprintf('%s: register your next term on Edus', $school->getName()))
            ->htmlTemplate('emails/subscription/invite.html.twig')
            ->context([
                'school_name' => $school->getName(),
                'url' => $url,
                'expires_days' => RegistrationInvite::LIFETIME_DAYS,
            ]);

        $this->mailer->send($email);

        return true;
    }

    /**
     * The invoice itself, with the PDF attached.
     *
     * The email exists so the school does not have to log in to discover it owes
     * money — the portal is where you pay, the email is how you find out.
     *
     * @param string $pdf      the rendered A4 document
     * @param string $filename what the bursar will see in their downloads folder
     *
     * @return bool false when the school has no usable billing address
     */
    public function sendInvoice(
        SubscriptionPayment $payment,
        string $pdf,
        string $filename,
        bool $hasBankAccount = false
    ): bool {
        $school = $payment->getSchool();
        $to = trim((string) $school?->getPrincipalEmail());

        if ($school === null || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $registration = $payment->getTermRegistration();
        $amount = number_format((float) $payment->getAmount(), 0);

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(new Address($to, (string) $school->getName()))
            ->replyTo($this->replyTo)
            ->subject(sprintf('Invoice %s — ₦%s due', $payment->getReference(), $amount))
            ->htmlTemplate('emails/subscription/invoice.html.twig')
            ->context([
                'school_name' => $school->getName(),
                'reference' => $payment->getReference(),
                'amount' => $amount,
                'plan_name' => $payment->getPlan()?->getName() ?? 'Subscription',
                'period' => $registration?->getPeriod() ?? 'this term',
                'declared_students' => $registration?->getDeclaredStudents() ?? '—',
                'portal_url' => $this->portalUrl($school, 'subscription/slip/' . $payment->getId()),
                'bank_account' => $hasBankAccount,
            ])
            ->attach($pdf, $filename, 'application/pdf');

        $this->mailer->send($email);

        return true;
    }

    /**
     * Absolute URL on the school's own subdomain.
     */
    private function portalUrl(School $school, string $path): ?string
    {
        $subdomain = trim((string) $school->getSubdomain());
        if ($subdomain === '') {
            return null;
        }

        $scheme = str_contains($this->baseDomain, 'localhost') ? 'http' : 'https';

        return sprintf('%s://%s.%s/%s', $scheme, $subdomain, $this->baseDomain, ltrim($path, '/'));
    }
}
