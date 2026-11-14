<?php

namespace App\Service\Students;

use App\Entity\Tenant\FamilyLinkNotice;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Entity\Tenant\VirtualAccount;
use App\Service\Phone\PhoneNumber;
use App\Service\Wording\LearnerWording;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Joining two parent records into one family.
 *
 * A school uploads its roll one file at a time and the same parent comes back
 * for the second, third and fourth child. When a number was typed wrongly, the
 * family ends up split across two `Guardian` rows — one child under each. This
 * class finds those rows and joins them, but only on evidence:
 *
 *   • the two rows carry the **same phone number** (however it was typed), and
 *   • at least one child shares a **surname or a non-empty middle name**.
 *
 * Two families can share a phone (an office number, a relative's handset), so
 * the name check is not decoration — it is the difference between merging a
 * family and putting a stranger's child on the wrong bill.
 */
final class FamilyLinker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LearnerWording $wording,
    ) {
    }

    /**
     * Other guardians already holding this number, in any spelling.
     *
     * @return list<Guardian>
     */
    public function guardiansWithPhone(string $phone, ?Guardian $exclude = null): array
    {
        $national = PhoneNumber::national($phone);

        if ($national === '') {
            return [];
        }

        $excludeId = $exclude?->getId();
        $ids = [];

        // One lightweight query. Phone numbers are stored in every shape the
        // office has typed over the years, so the comparison happens on the
        // digits, not on the string.
        foreach ($this->em->createQuery(
            'SELECT g.id, g.phoneNumber FROM ' . Guardian::class . ' g'
        )->getArrayResult() as $row) {
            $id = (int) $row['id'];

            if ($excludeId !== null && $id === $excludeId) {
                continue;
            }

            if (PhoneNumber::national((string) ($row['phoneNumber'] ?? '')) === $national) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (int $id): ?Guardian => $this->em->find(Guardian::class, $id),
            array_values(array_unique($ids))
        )));
    }

    /**
     * The shared name between two pupils, or null.
     *
     * The school's rule is deliberately narrow: **surname, or a non-empty
     * middle name**. A pupil with no middle name must not match every other
     * pupil with no middle name, so empty strings never count.
     *
     * @return string|null a human label such as `surname “Bello”`
     */
    public static function nameMatch(Student $left, Student $right): ?string
    {
        $leftSurname = self::normaliseName($left->getLastName());
        $rightSurname = self::normaliseName($right->getLastName());

        if ($leftSurname !== '' && $leftSurname === $rightSurname) {
            return sprintf('surname “%s”', trim((string) $left->getLastName()));
        }

        $leftMiddle = self::normaliseName($left->getMiddleName());
        $rightMiddle = self::normaliseName($right->getMiddleName());

        if ($leftMiddle !== '' && $leftMiddle === $rightMiddle) {
            return sprintf('middle name “%s”', trim((string) $left->getMiddleName()));
        }

        return null;
    }

    /**
     * Every child pair that proves these two rows are one family.
     *
     * @return array{labels: list<string>, pairs: list<array{source: Student, target: Student, label: string}>, targetChildren: list<Student>}
     */
    public function familyMatches(Guardian $source, Guardian $target): array
    {
        $labels = [];
        $pairs = [];

        foreach ($source->getStudents() as $sourceChild) {
            foreach ($target->getStudents() as $targetChild) {
                $label = self::nameMatch($sourceChild, $targetChild);

                if ($label === null) {
                    continue;
                }

                $labels[$label] = true;
                $pairs[] = [
                    'source' => $sourceChild,
                    'target' => $targetChild,
                    'label' => $label,
                ];
            }
        }

        return [
            'labels' => array_keys($labels),
            'pairs' => $pairs,
            'targetChildren' => $target->getStudents()->toArray(),
        ];
    }

    /**
     * Why an automatic merge must not happen, or null when it may.
     *
     * A dedicated virtual account belongs to the guardian and is unique. Two
     * records with one each cannot become one record without orphaning a bank
     * account, so the office is told to keep them separate and use the manual
     * "add a child" flow instead.
     */
    public function mergeBlockedReason(Guardian $source, Guardian $target): ?string
    {
        if ($source->getId() !== null && $source->getId() === $target->getId()) {
            return null;
        }

        $sourceAccount = $this->em->getRepository(VirtualAccount::class)->findOneBy(['guardian' => $source]);
        $targetAccount = $this->em->getRepository(VirtualAccount::class)->findOneBy(['guardian' => $target]);

        if ($sourceAccount !== null && $targetAccount !== null) {
            return 'Both parent records already have a dedicated bank account (virtual account). '
                . 'Merging them would leave one of those accounts without an owner, so nothing was changed. '
                . 'Use “Add a child” on the correct family instead, so the bank accounts stay with the right parent.';
        }

        return null;
    }

    /**
     * Move every child and application from $source into $target.
     *
     * The target keeps its own name, login and bank account. The source record
     * is removed when nothing of its own is left behind; when it has a virtual
     * account that account moves across first, so the family's bank details are
     * not lost.
     *
     * @return array{moved: list<string>, sourceRemoved: bool, virtualAccountMoved: bool}
     */
    public function merge(Guardian $source, Guardian $target, ?string $actor = null): array
    {
        if ($this->mergeBlockedReason($source, $target) !== null) {
            throw new \LogicException('These parent records cannot be merged automatically.');
        }

        $moved = [];

        foreach ($source->getStudents()->toArray() as $student) {
            $source->removeStudent($student);
            $target->addStudent($student);
            $this->createNotice($target, $student, $actor, true);
            $moved[] = $student->getFullName() ?? 'this pupil';
        }

        foreach ($source->getApplications()->toArray() as $application) {
            $source->removeApplication($application);
            $target->addApplication($application);
        }

        $sourceAccount = $this->em->getRepository(VirtualAccount::class)->findOneBy(['guardian' => $source]);
        $virtualAccountMoved = false;

        if ($sourceAccount !== null) {
            $sourceAccount->setGuardian($target);
            $this->em->persist($sourceAccount);
            $virtualAccountMoved = true;
        }

        // The family keeps one login. If only the source has one, hand it to
        // the target before the source (and its cascade-removed user) goes.
        if ($target->getUser() === null && $source->getUser() !== null) {
            $target->setUser($source->getUser());
            $source->setUser(null);
        }

        // A shared user object would otherwise be cascade-removed with the
        // source and take the surviving family's login with it.
        if ($source->getUser() !== null && $source->getUser() === $target->getUser()) {
            $source->setUser(null);
        }

        $sourceRemoved = false;

        // No virtual account is left on the source, so the row can go.
        if ($sourceAccount === null || $virtualAccountMoved) {
            $this->em->remove($source);
            $sourceRemoved = true;
        }

        $this->em->flush();

        return [
            'moved' => $moved,
            'sourceRemoved' => $sourceRemoved,
            'virtualAccountMoved' => $virtualAccountMoved,
        ];
    }

    /**
     * Put a set of pupils under one guardian.
     *
     * This is the "an uncle came to say the child is in his care" flow. A pupil
     * who already belongs to another family is moved, and the notice tells the
     * receiving parent exactly what changed.
     *
     * @param list<int> $studentIds
     *
     * @return array{linked: list<string>, skipped: list<string>}
     */
    public function linkStudents(Guardian $guardian, array $studentIds, ?string $actor = null): array
    {
        $linked = [];
        $skipped = [];

        foreach (array_values(array_unique(array_map('intval', $studentIds))) as $id) {
            if ($id <= 0) {
                continue;
            }

            $student = $this->em->find(Student::class, $id);

            if ($student === null) {
                continue;
            }

            if ($student->getGuardian()?->getId() === $guardian->getId()) {
                $skipped[] = $student->getFullName() ?? 'A pupil';

                continue;
            }

            $student->setGuardian($guardian);
            $guardian->addStudent($student);
            $this->createNotice($guardian, $student, $actor, false);
            $linked[] = $student->getFullName() ?? 'A pupil';
        }

        $this->em->flush();

        return ['linked' => $linked, 'skipped' => $skipped];
    }

    /**
     * Keep a phone-derived login in step with a corrected contact number.
     *
     * Only addresses on the platform's login domain are touched: a parent who
     * registered with their own e-mail keeps that e-mail. Returns a sentence the
     * form can show when the new address is already taken.
     */
    public function moveLoginToPhone(Guardian $guardian, string $oldPhone, string $newPhone): ?string
    {
        $oldNational = PhoneNumber::national($oldPhone);
        $newNational = PhoneNumber::national($newPhone);

        if ($oldNational === '' || $newNational === '' || $oldNational === $newNational) {
            return null;
        }

        $email = (string) $guardian->getEmail();
        $at = strrpos($email, '@');

        if ($at === false || strtolower(substr($email, $at + 1)) !== PhoneNumber::LOGIN_DOMAIN) {
            return null;
        }

        $local = substr($email, 0, $at);

        if (!ctype_digit($local) || PhoneNumber::national($local) !== $oldNational) {
            return null;
        }

        $newEmail = PhoneNumber::loginEmail($newNational);

        if (strcasecmp($email, $newEmail) === 0) {
            return null;
        }

        $otherGuardian = $this->em->getRepository(Guardian::class)->findOneBy(['email' => $newEmail]);
        if ($otherGuardian !== null && $otherGuardian->getId() !== $guardian->getId()) {
            return sprintf(
                'The sign-in address for this number (%s) already belongs to %s. Nothing was saved — check the number, or ask an administrator to merge the two parent records.',
                $newEmail,
                $otherGuardian->getFullName() ?: 'another parent'
            );
        }

        $otherUser = $this->em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
        if ($otherUser !== null && $otherUser->getId() !== $guardian->getUser()?->getId()) {
            return sprintf(
                'The sign-in address for this number (%s) is already in use by another account. Nothing was saved — check the number with the parent.',
                $newEmail
            );
        }

        $guardian->setEmail($newEmail);

        $user = $guardian->getUser();
        if ($user !== null && strcasecmp((string) $user->getEmail(), $email) === 0) {
            $user->setEmail($newEmail);
        }

        return null;
    }

    /** The sentence shown when a number is shared but no child's name matches. */
    public function noNameMatchMessage(Guardian $edited, array $others): string
    {
        $descriptions = [];

        foreach ($others as $other) {
            $children = array_map(
                static fn (Student $student): string => $student->getFullName() ?? 'a pupil',
                $other->getStudents()->toArray()
            );

            $descriptions[] = sprintf(
                '%s%s',
                $other->getFullName() ?: 'another parent',
                $children === [] ? ' (no children linked)' : ' — ' . implode(', ', $children)
            );
        }

        $ownChildren = array_map(
            static fn (Student $student): string => $student->getFullName() ?? 'a pupil',
            $edited->getStudents()->toArray()
        );

        return sprintf(
            'That number already belongs to %s. We only join two parent records when a child shares a surname or a middle name. '
            . '%s, so nothing was changed. If this really is the same family, open the other parent\'s family page and use '
            . '“Add a child”.',
            implode('; ', $descriptions),
            $ownChildren === []
                ? 'No children are linked to this parent record'
                : 'The children here (' . implode(', ', $ownChildren) . ') do not share a name with them'
        );
    }

    private function createNotice(Guardian $guardian, Student $student, ?string $actor, bool $viaMerge): void
    {
        $learner = $this->wording->singular();
        $name = $student->getFullName() ?? ('A new ' . $learner);

        $message = $viaMerge
            ? sprintf(
                'The family contact number was corrected, and %s is now under your family as a sibling.',
                $name
            )
            : sprintf(
                'A new %s, %s, has been added to your family as a sibling.',
                $learner,
                $name
            );

        $notice = new FamilyLinkNotice();
        $notice->setGuardian($guardian)
            ->setStudent($student)
            ->setLinkedBy($actor)
            ->setMessage($message . ' If you think this is a mistake, report it to the school management or write a note below.');

        $this->em->persist($notice);
        $guardian->addFamilyNotice($notice);
    }

    private static function normaliseName(?string $value): string
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));

        return $value;
    }
}
