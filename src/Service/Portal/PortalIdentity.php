<?php

namespace App\Service\Portal;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Is this account standing on both sides of the desk?
 *
 * A bursar whose own children attend the school is one person with one login and
 * two jobs: they work here *and* they are a parent here. This answers that
 * question from the two things that actually grant the second door — a staff
 * role/profile, and ROLE_PARENT on the same login. Nothing is stored on the user.
 *
 * A Guardian row is deliberately NOT required. A teacher added to the school
 * before their child was admitted (or a record whose guardian row was created
 * without a login) still holds ROLE_PARENT, and hiding the choice from them is
 * what left such accounts stuck in the parent shell with no way across. The
 * parent portal itself handles an empty family gracefully.
 */
final class PortalIdentity
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function guardianOf(User $user): ?Guardian
    {
        return $this->em->getRepository(Guardian::class)->findOneBy(['user' => $user]);
    }

    public function isDual(User $user): bool
    {
        return $user->holdsStaffRole()
            && in_array('ROLE_PARENT', $user->getRoles(), true);
    }

    /** The session key the shell and the provider read to place this person. */
    public function modeFrom(?string $mode): string
    {
        return $mode === 'parent' ? 'parent' : 'staff';
    }
}
