<?php

namespace App\Service\Security;

use App\Entity\Tenant\School;
use App\Entity\Tenant\User;
use App\Security\TenantUserProvider;
use App\Service\Mail\AddressCheck;
use App\Service\Mail\PasswordResetMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "I forgot my password" — for staff and parents alike.
 *
 * One rule shapes the whole flow: **the inbox is the proof**. The person types
 * the email or phone they sign in with, and only if a *deliverable* mailbox is on
 * that account do we mail a single-use link. Nothing is ever revealed about
 * whether an account exists: the page answers the same way either way.
 *
 * Accounts whose login is a phone-derived address (`8031234567@edus.ng`) cannot
 * receive mail. Those are reset by the school office (parent family page / staff
 * screen), and the person can add a real address under "My account" once in.
 */
final class PasswordResetService
{
    /** How long the mailed link stays usable. */
    private const TTL_MINUTES = 60;

    /** Same floor as the change-password form and the staff form. */
    private const MIN_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordResetMailer $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Start a reset. Returns false when no link was sent — a missing account, or
     * one without a usable mailbox — and the caller must not say which.
     */
    public function request(string $identifier): bool
    {
        $user = $this->findLogin($identifier);

        if ($user === null) {
            return false;
        }

        $email = (string) $user->getEmail();

        if (!AddressCheck::isDeliverable($email)) {
            $this->logger->info('Password reset requested for an account with no deliverable mailbox.', [
                'user' => $user->getId(),
            ]);

            return false;
        }

        // 32 random bytes, hex. Only the hash is stored: a database copy must
        // never be replayable into somebody's account.
        $token = bin2hex(random_bytes(32));

        $user->setResetTokenHash(hash('sha256', $token));
        $user->setResetRequestedAt(new \DateTimeImmutable());
        $this->em->flush();

        $url = $this->urls->generate(
            'app_reset_password',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->mailer->send($user, $url, $this->em->getRepository(School::class)->findOneBy([]), self::TTL_MINUTES);

        return true;
    }

    /** The account a typed email or phone number belongs to, in any spelling. */
    public function findLogin(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        foreach (TenantUserProvider::candidates($identifier) as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    /** The account behind a link, or null when the link is spent or stale. */
    public function userForToken(string $token): ?User
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $user = $this->em->getRepository(User::class)->findOneBy([
            'resetTokenHash' => hash('sha256', $token),
        ]);

        if ($user === null || $user->getResetRequestedAt() === null) {
            return null;
        }

        if ($user->getResetRequestedAt() < new \DateTimeImmutable(sprintf('-%d minutes', self::TTL_MINUTES))) {
            $user->clearPasswordReset();
            $this->em->flush();

            return null;
        }

        return $user;
    }

    /** Set the new password and burn the link. */
    public function reset(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        // They chose this one themselves, so the forced-change door is closed.
        $user->setMustChangePassword(false);
        $user->clearPasswordReset();

        $this->em->flush();
    }

    public static function minimumLength(): int
    {
        return self::MIN_LENGTH;
    }
}
