<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use App\Entity\Tenant\User;
use App\Form\ParentRegistrationType;
use App\Service\ApplicationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * PUBLIC "Application Mode" — the parent side of online admissions.
 *
 *   /apply            landing page (open / closed states)
 *   /apply/register   parent creates the account that becomes their parent-portal login
 *   /apply/verify/{id} email link → marks the address verified + signs them in
 *   /apply/recover    "an account may already exist" → sign-in link (never leaks details)
 *
 * Nothing here needs a login, which is why /apply is PUBLIC_ACCESS in
 * security.yaml (above the catch-all — see the /admin login-loop incident).
 */
#[Route('/apply', host: '{subdomain}.%app.base_domain%')]
class ApplicationController extends AbstractController
{
    /** How long a sign-in / verification link stays valid. */
    private const LINK_TTL_MINUTES = 120;

    public function __construct(
        private EntityManagerInterface $em,
        private ApplicationMailer $mailer,
        private UriSigner $uriSigner,
        private Security $security,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Landing page
    // ─────────────────────────────────────────────────────────────────────
    #[Route('', name: 'app_tenant_apply_index', methods: ['GET'])]
    public function index(): Response
    {
        $school = $this->currentSchool();

        return $this->render('tenant/apply/index.html.twig', [
            'school' => $school,
            'open'   => (bool) $school?->isApplicationsOpen(),
            'user'   => $this->security->getUser(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Parent self-registration
    // ─────────────────────────────────────────────────────────────────────
    #[Route('/register', name: 'app_tenant_apply_register', methods: ['GET', 'POST'])]
    public function register(Request $request, UserPasswordHasherInterface $hasher): Response
    {
        $school = $this->currentSchool();

        if (!$school?->isApplicationsOpen()) {
            $this->addFlash('info', 'Online applications are not open at the moment.');

            return $this->redirectToRoute('app_tenant_apply_index');
        }

        // Already signed in → they should apply from inside the portal.
        if ($this->security->getUser()) {
            return $this->redirectToRoute('app_parent_dashboard');
        }

        $guardian = new Guardian();
        $form = $this->createForm(ParentRegistrationType::class, $guardian);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = strtolower(trim((string) $guardian->getEmail()));
            $phone = $this->digits((string) $guardian->getPhoneNumber());

            // 1️⃣ The email they typed may already be on file → genuine duplicate.
            //    Send the sign-in link to THAT address and show the neutral page.
            $byEmail = $this->findGuardianByEmail($email);
            if ($byEmail) {
                $this->sendAccessLink($byEmail, ApplicationMailer::PURPOSE_RECOVER);

                return $this->render('tenant/apply/sent.html.twig', [
                    'school'  => $school,
                    'contact' => $email,
                    'reason'  => 'email',
                ]);
            }

            // 2️⃣ The phone may belong to a family already on file. That is NOT
            //    the same thing as "you already have an account":
            //    • if the address on file is usable we keep ONE account per family
            //      and send the link there (no new record, honest wording);
            //    • if it is an auto-generated, undeliverable address (bulk imports
            //      created things like 08033...@placeholder.com) we must NOT send a
            //      parent into a dead end — we let them register and flag it for the office.
            $byPhone = $this->findGuardianByPhone($phone);
            if ($byPhone && $this->isDeliverableEmail($byPhone->getEmail())) {
                $this->logger->info('Application registration blocked: phone already registered with a usable email.', [
                    'phone'          => $phone,
                    'existing_email' => $byPhone->getEmail(),
                ]);
                $this->sendAccessLink($byPhone, ApplicationMailer::PURPOSE_RECOVER);

                return $this->render('tenant/apply/sent.html.twig', [
                    'school'  => $school,
                    'contact' => $phone,
                    'reason'  => 'phone',
                ]);
            }

            if ($byPhone) {
                // Auto-generated/undeliverable address on the existing record —
                // let the parent register and leave a trail for the bursar.
                $this->logger->warning('New parent account created on a phone number that already exists with an undeliverable email.', [
                    'phone'            => $phone,
                    'existing_email'   => $byPhone->getEmail(),
                    'new_email'        => $email,
                ]);
            }

            // 2️⃣ Brand-new family: create the account that is now their
            //    permanent parent-portal login.
            $guardian->setEmail($email);
            $guardian->setFullName((string) $guardian->getFullName());
            $guardian->setPhoneNumber((string) $guardian->getPhoneNumber());
            $guardian->setSource('self');
            $guardian->setIsVerified(false);

            $user = new User();
            $user->setEmail($email);
            $user->setFullName((string) $guardian->getFullName());
            $user->setRoles(['ROLE_PARENT']);
            $user->setSchool($school);
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));

            $guardian->setUser($user);

            $this->em->persist($user);
            $this->em->persist($guardian);
            $this->em->flush();

            // 3️⃣ Send the verification link, then sign them in: the link proves
            //    the email is theirs, it should not be a second gate in front of
            //    filling the application form.
            $this->sendAccessLink($guardian, ApplicationMailer::PURPOSE_VERIFY);
            $this->security->login($user, 'form_login', 'main');

            $this->addFlash('success', 'Your account is ready. We have emailed a confirmation link — please click it when you can.');

            return $this->redirectToRoute('app_parent_dashboard');
        }

        return $this->render('tenant/apply/register.html.twig', [
            'school' => $school,
            'form'   => $form->createView(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Email link → verify + sign in
    // ─────────────────────────────────────────────────────────────────────
    #[Route('/verify/{id}', name: 'app_tenant_apply_verify', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function verify(Guardian $guardian, Request $request): Response
    {
        if (!$this->isValidLink($request)) {
            $this->addFlash('error', 'That link is invalid or has expired. Request a fresh one below.');

            return $this->redirectToRoute('app_tenant_apply_recover');
        }

        if (!$guardian->getEmailVerifiedAt()) {
            $now = new \DateTimeImmutable();
            $guardian->setEmailVerifiedAt($now);
            $guardian->setIsVerified(true);
            $guardian->setVerifiedAt($now);
            $this->em->flush();
        }

        $user = $guardian->getUser();
        if ($user && !$this->security->getUser()) {
            $this->security->login($user, 'form_login', 'main');
        }

        $this->addFlash('success', 'Email confirmed — welcome to your parent portal.');

        return $this->redirectToRoute($user ? 'app_parent_dashboard' : 'app_tenant_apply_index');
    }

    // ─────────────────────────────────────────────────────────────────────
    // "I think I already have an account" → sign-in link (also our password
    // recovery path: no password is needed, the inbox is the proof).
    // ─────────────────────────────────────────────────────────────────────
    #[Route('/recover', name: 'app_tenant_apply_recover', methods: ['GET', 'POST'])]
    public function recover(Request $request): Response
    {
        $school = $this->currentSchool();

        if ($request->isMethod('POST')) {
            $contact = trim((string) $request->request->get('contact'));
            $email = filter_var($contact, FILTER_VALIDATE_EMAIL) ? strtolower($contact) : null;

            // Only send to an address we can actually reach.
            $guardian = $email
                ? $this->findGuardianByEmail($email)
                : $this->findGuardianByPhone($this->digits($contact));

            if ($guardian && $this->isDeliverableEmail($guardian->getEmail())) {
                $this->sendAccessLink($guardian, ApplicationMailer::PURPOSE_RECOVER);
            } elseif ($guardian) {
                // Matched, but the school has no usable email on file for them.
                $this->logger->info('Sign-in link requested for a guardian with an undeliverable address.', [
                    'guardian' => $guardian->getId(),
                ]);
            }

            // Identical response whether or not we found anything.
            return $this->render('tenant/apply/sent.html.twig', [
                'school'  => $school,
                'contact' => $contact,
            ]);
        }

        return $this->render('tenant/apply/recover.html.twig', [
            'school' => $school,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Convenience: /apply/login → the school's normal login page
    // ─────────────────────────────────────────────────────────────────────
    #[Route('/login', name: 'app_tenant_apply_login', methods: ['GET'])]
    public function login(): Response
    {
        return $this->redirectToRoute('app_login');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════

    private function currentSchool(): ?School
    {
        return $this->em->getRepository(School::class)->findOneBy([]);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Match a family by the email they typed.
     */
    private function findGuardianByEmail(string $email): ?Guardian
    {
        if ($email === '') {
            return null;
        }

        return $this->em->getRepository(Guardian::class)->findOneBy(['email' => $email]);
    }

    /**
     * Match a family by the last 10 digits of the phone, so "0803 123 4567",
     * "+2348031234567" and "0803-123-4567" all find the same row.
     * (DQL has no REPLACE() function, so the normalising happens in SQL.)
     */
    private function findGuardianByPhone(string $phone): ?Guardian
    {
        if (strlen($phone) < 10) {
            return null;
        }

        $id = $this->em->getConnection()->fetchOne(
            "SELECT id FROM guardian
              WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?
              LIMIT 1",
            ['%' . substr($phone, -10)]
        );

        return $id ? $this->em->getRepository(Guardian::class)->find((int) $id) : null;
    }

    /**
     * Can we actually reach this address? Bulk imports created addresses like
     * 08033123456@placeholder.com and 08033123456@edus.ng which nobody can read —
     * treating those as "your account" trapped parents in a dead end.
     */
    private function isDeliverableEmail(?string $email): bool
    {
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        foreach (['placeholder.com', 'edus.ng', 'edus.local', 'example.com', 'localhost'] as $unusable) {
            if ($domain === $unusable || str_ends_with($domain, '.' . $unusable)) {
                return false;
            }
        }

        // "...@gmail" style typos and .test/.local cannot receive mail either.
        return str_contains($domain, '.');
    }

    private function sendAccessLink(Guardian $guardian, string $purpose): void
    {
        $school = $this->currentSchool();
        if (!$school || !$guardian->getEmail()) {
            return;
        }

        try {
            $this->mailer->sendAccessLink(
                $school,
                $guardian,
                $this->signAccessUrl($guardian, $purpose),
                $purpose,
                self::LINK_TTL_MINUTES
            );
        } catch (\Throwable $e) {
            // A mail outage must never lose a registration.
            $this->logger->error('Access link email failed: ' . $e->getMessage(), [
                'guardian' => $guardian->getId(),
                'purpose'  => $purpose,
            ]);
            $this->addFlash('error', 'We could not send the email just now. Please use "Get a sign-in link" again in a moment.');
        }
    }

    /** Signed, time-limited URL — verified with UriSigner + the `expires` stamp. */
    private function signAccessUrl(Guardian $guardian, string $purpose): string
    {
        // The /apply routes are host-restricted to {subdomain}.<base>, so the
        // tenant subdomain is a mandatory route parameter for URL generation.
        $subdomain = $this->requestStack->getCurrentRequest()?->attributes->get('subdomain');

        $url = $this->generateUrl(
            'app_tenant_apply_verify',
            [
                'subdomain' => $subdomain,
                'id'      => $guardian->getId(),
                'purpose' => $purpose,
                'expires' => (new \DateTimeImmutable())->modify('+' . self::LINK_TTL_MINUTES . ' minutes')->getTimestamp(),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->uriSigner->sign($url);
    }

    private function isValidLink(Request $request): bool
    {
        $expires = (int) $request->query->get('expires', 0);
        if ($expires < time()) {
            return false;
        }

        return $this->uriSigner->check($request->getUri());
    }
}
