<?php

namespace App\Security;

use App\Entity\Tenant\User;
use App\Service\Phone\PhoneNumber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Tenant login lookup, with one deliberate difference from the stock entity
 * provider: a parent's login is derived from their phone number, and people type
 * that number in three different ways.
 *
 * The importer creates 08039658200@edus.ng. A parent who knows the number but
 * not the convention will type 8039658200@edus.ng or 2348039658200@edus.ng —
 * both are the same subscriber, and before this provider both ended in
 * "Invalid credentials." on a correct password. That is a support call the
 * school cannot answer, because the parent is not wrong about their own number.
 *
 * Only addresses on the platform domain whose local part is nothing but digits
 * are rewritten; a real mailbox like ada@gmail.com is passed through untouched.
 *
 * @see \App\Controller\Tenant\QuickAdmissionController::normalisePhone() — the
 *      same rule, applied when the record is written.
 */
final class TenantUserProvider implements UserProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // People sign in with whatever the school gave them: the phone number
        // itself ("0803 123 4567"), the derived address, the legacy spelling
        // with the trunk zero, or a real inbox. All of them name one account.
        foreach (self::candidates($identifier) as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user !== null) {
                return $user;
            }
        }

        // Same message for "no such account" and a wrong password — never
        // confirm which addresses exist.
        throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
    }

    /**
     * Every form a typed identifier may take, in the order we try them.
     *
     * A phone number is accepted in any shape the office or the parent writes it
     * — `0803 123 4567`, `0803-123-4567`, `+234 803 123 4567`, `8031234567` —
     * because that is the credential the school actually handed out. It is
     * normalised to the stored national form and then expanded to the derived
     * login spellings. A real mailbox passes through untouched.
     *
     * @return list<string>
     */
    public static function candidates(string $identifier): array
    {
        $identifier = trim($identifier);

        if (!str_contains($identifier, '@')) {
            $digits = PhoneNumber::digits($identifier);

            if ($digits === '') {
                return [$identifier];
            }

            $identifier = PhoneNumber::loginEmail($digits);
        }

        return PhoneNumber::loginCandidates($identifier);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $fresh = $user->getId() !== null ? $this->em->find(User::class, $user->getId()) : null;

        if ($fresh === null) {
            throw new UserNotFoundException('The signed-in account no longer exists.');
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}
