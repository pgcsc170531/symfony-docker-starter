<?php

namespace App\Service\Staff;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\User;
use App\Service\Phone\PhoneMatcher;
use App\Service\Students\FamilyLinker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Joining a staff login to the parent login that is the same person.
 *
 * The rule is the same one `/staff/new` uses — same phone **or** same email is the
 * same person — but this is the repair path. It exists because a school that
 * registered the teacher *before* the parent (or used the CSV import, or created
 * the staff account when the parent rule did not exist yet) ends up with two rows:
 * aisha@school.com holding ROLE_TEACHER, and 8039658200@edus.ng holding
 * ROLE_PARENT and the children. Neither screen could join them.
 *
 * The **staff** login survives: it is the one the office administers, with the
 * timetable, assignments and staff number. Every Guardian row moves to it (merged
 * through {@see FamilyLinker} when it already owns one), it gains ROLE_PARENT, and
 * the parent login is deleted once nothing points at it. The parent password is
 * not carried over — the person signs in with the staff address from then on.
 */
final class StaffParentLinker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FamilyLinker $families,
        private readonly PhoneMatcher $phones,
    ) {
    }

    /**
     * A parent-only login is one that has children here and no job here.
     *
     * Anything else must not be swept up: a pupil login, another staff member, or
     * an account that already holds both roles (nothing left to join).
     */
    public function isParentOnly(User $user): bool
    {
        return $user->getStudent() === null
            && !$user->holdsStaffRole()
            && in_array('ROLE_PARENT', $user->getRoles(), true);
    }

    /**
     * Parent-only logins whose family record carries this phone or email.
     *
     * Used by the staff screen to *show* the office who it thinks the person is,
     * before anything is changed.
     *
     * @return list<User>
     */
    public function candidates(User $staff, string $phone, string $email): array
    {
        $found = [];

        foreach ($this->familyRows($phone, $email) as $guardian) {
            $user = $guardian->getUser();

            if ($user === null || $user->getId() === $staff->getId() || !$this->isParentOnly($user)) {
                continue;
            }

            $found[(int) $user->getId()] = $user;
        }

        return array_values($found);
    }

    /**
     * Family rows matching either contact, in every stored spelling.
     *
     * Phones are compared on digits (`0803 965 8200`, `+234803…`, `803…` are one
     * number); emails case-insensitively.
     *
     * @return list<Guardian>
     */
    public function familyRows(string $phone, string $email): array
    {
        $rows = [];

        foreach ($this->phones->guardiansByPhone($phone, 20) as $guardian) {
            $rows[(int) $guardian->getId()] = $guardian;
        }

        $email = mb_strtolower(trim($email));

        if ($email !== '') {
            foreach ($this->em->getRepository(Guardian::class)->createQueryBuilder('g')
                ->andWhere('LOWER(g.email) = :email')
                ->setParameter('email', $email)
                ->getQuery()
                ->getResult() as $guardian) {
                $rows[(int) $guardian->getId()] = $guardian;
            }
        }

        return array_values($rows);
    }

    /**
     * Move the parent login's family onto the staff login.
     *
     * @throws \LogicException when the pair cannot be joined safely (the caller
     *                         shows the message — it always explains why).
     *
     * @return array{moved: list<string>, retired: bool, stillUsed: int}
     */
    public function link(User $staff, User $parent, ?string $actor = null): array
    {
        if ($staff->getId() === $parent->getId()) {
            throw new \LogicException('Those are the same account — there is nothing to join.');
        }

        if (!$staff->holdsStaffRole()) {
            throw new \LogicException('The surviving account must be the staff login.');
        }

        if (!$this->isParentOnly($parent)) {
            throw new \LogicException(sprintf(
                '%s is not a parent-only login (it is a pupil login, another staff member, or already linked).',
                (string) $parent->getEmail()
            ));
        }

        $sources = $this->em->getRepository(Guardian::class)->findBy(['user' => $parent], ['id' => 'ASC']);

        if ($sources === []) {
            throw new \LogicException(sprintf(
                '%s owns no family record, so there are no children to move.',
                (string) $parent->getEmail()
            ));
        }

        // One Guardian row per login (it is a OneToOne), so a staff login that
        // already owns a family row can only absorb the other by merging.
        $target = $this->em->getRepository(Guardian::class)->findOneBy(['user' => $staff]);
        $repoint = null;
        $merges = $sources;

        if ($target === null) {
            $repoint = array_shift($merges);
            $target = $repoint;
        }

        foreach ($merges as $source) {
            $blocked = $this->families->mergeBlockedReason($source, $target);

            if ($blocked !== null) {
                throw new \LogicException($blocked);
            }
        }

        $moved = [];

        if ($repoint !== null) {
            $repoint->setUser($staff);
            $this->em->flush();
        }

        foreach ($merges as $source) {
            $result = $this->families->merge($source, $target, $actor);
            $moved = array_merge($moved, $result['moved']);
        }

        $staff->setRoles(array_values(array_unique(array_filter(
            array_merge($staff->getRoles(), ['ROLE_PARENT']),
            static fn (string $role): bool => $role !== 'ROLE_USER'
        ))));

        $stillUsed = (int) $this->em->getRepository(Guardian::class)->count(['user' => $parent]);
        $retired = false;

        if ($stillUsed === 0) {
            $this->em->remove($parent);
            $retired = true;
        }

        $this->em->flush();

        return ['moved' => $moved, 'retired' => $retired, 'stillUsed' => $stillUsed];
    }
}
