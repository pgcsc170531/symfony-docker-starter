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
 * question from the two records that prove it — a Staff profile, and a Guardian
 * row that owns at least one pupil. Nothing is stored on the user.
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

    /**
     * Staff who are also a parent of a pupil in this school.
     *
     * A guardian record with no children is not enough: an application that was
     * never admitted would otherwise offer a parent portal with nothing in it.
     */
    public function isDual(User $user): bool
    {
        if (!$user->holdsStaffRole()) {
            return false;
        }

        $guardian = $this->guardianOf($user);

        return $guardian !== null && $guardian->getStudents()->count() > 0;
    }

    /** The session key the shell and the provider read to place this person. */
    public function modeFrom(?string $mode): string
    {
        return $mode === 'parent' ? 'parent' : 'staff';
    }
}
