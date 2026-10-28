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
        // 8039658200@edus.ng is what a parent is given now; 08039658200@edus.ng
        // is what earlier imports created; 2348039658200@edus.ng is what people
        // type. All three must reach the same account.
        foreach (PhoneNumber::loginCandidates($identifier) as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user !== null) {
                return $user;
            }
        }

        // Same message for "no such account" and a wrong password — never
        // confirm which addresses exist.
        throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
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
