<?php

namespace App\Service\SchoolLife;

use App\Entity\Tenant\BehaviourTrait;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds the standard Nigerian affective and psychomotor trait lists once per school.
 *
 * Idempotent by design: if the school already has any trait it does nothing, so a
 * school that renamed every trait is never "reset" by a later page load.
 *
 * Called lazily from the behaviour screens rather than from a migration, because a
 * new school should not need a console command before a form master can rate a
 * pupil.
 */
final class BehaviourTraitSeeder
{
    /** @var list<string> */
    private const AFFECTIVE = [
        'Punctuality',
        'Attentiveness',
        'Neatness',
        'Politeness',
        'Honesty',
        'Relationship with others',
        'Self-control',
        'Spirit of cooperation',
        'Attitude to work',
        'Perseverance',
        'Leadership',
    ];

    /** @var list<string> */
    private const PSYCHOMOTOR = [
        'Handwriting',
        'Verbal fluency',
        'Games & sports',
        'Drawing & painting',
        'Handling of tools',
        'Musical skills',
        'Dexterity',
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return int number of traits created (0 when already seeded) */
    public function ensureSeeded(): int
    {
        $existing = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(BehaviourTrait::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        if ($existing > 0) {
            return 0;
        }

        $order = 0;
        foreach (self::AFFECTIVE as $label) {
            $this->em->persist(new BehaviourTrait(BehaviourTrait::AFFECTIVE, $label, $order += 10));
        }

        $order = 0;
        foreach (self::PSYCHOMOTOR as $label) {
            $this->em->persist(new BehaviourTrait(BehaviourTrait::PSYCHOMOTOR, $label, $order += 10));
        }

        $this->em->flush();

        return count(self::AFFECTIVE) + count(self::PSYCHOMOTOR);
    }
}
