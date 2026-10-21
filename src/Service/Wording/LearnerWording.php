<?php

namespace App\Service\Wording;

use App\Entity\Tenant\School;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One word, decided once.
 *
 * A primary school calls the children on its roll "pupils". A secondary school,
 * a combined nursery-and-secondary school and a college call them "students".
 * Before this service every screen guessed, so one portal said "Pupils rated"
 * on top and "Student" underneath.
 *
 * The rule is deliberately one-directional: **only** a pure primary school says
 * "pupil". Anything else — including `combined`, which has secondary pupils in
 * it — says "student". That is the reading a parent at a combined school
 * expects, and it is the safer default when the type has never been set.
 *
 * Usage in Twig (registered as the `learner` global in `config/packages/twig.yaml`):
 *
 *   {{ learner }}                pupil   | student
 *   {{ learner.plural }}         pupils  | students
 *   {{ learner.capital }}        Pupil   | Student
 *   {{ learner.pluralCapital }}  Pupils  | Students
 */
final class LearnerWording
{
    private ?string $institutionType = null;

    private bool $resolved = false;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * The school's `institution_type`, resolved lazily and at most once per
     * request. Public pages, PDF renders and landlord screens all reach this
     * service; when there is no tenant connection (or the row has not been
     * created yet) it must not throw — it just falls back to the neutral word.
     */
    public function institutionType(): string
    {
        if ($this->resolved) {
            return $this->institutionType;
        }

        $this->resolved = true;

        try {
            $school = $this->em->getRepository(School::class)->findOneBy([]);
            $this->institutionType = $school?->getInstitutionType() ?: School::TYPE_SECONDARY;
        } catch (\Throwable) {
            $this->institutionType = School::TYPE_SECONDARY;
        }

        return $this->institutionType;
    }

    public function isPrimary(): bool
    {
        // Nursery counts: a three-year-old is a pupil, not a student.
        return \in_array($this->institutionType(), [School::TYPE_NURSERY, School::TYPE_PRIMARY], true);
    }

    /** `{{ learner }}` */
    public function __toString(): string
    {
        return $this->singular();
    }

    public function singular(): string
    {
        return $this->isPrimary() ? 'pupil' : 'student';
    }

    public function plural(): string
    {
        return $this->isPrimary() ? 'pupils' : 'students';
    }

    public function capital(): string
    {
        return ucfirst($this->singular());
    }

    public function pluralCapital(): string
    {
        return ucfirst($this->plural());
    }

    /**
     * Rewrite a stored sentence that was written with a fixed word, e.g. a
     * school's "What parents must prepare" note saying "the pupil's last
     * result". Only a secondary-or-above school is rewritten — a primary school
     * already reads correctly.
     */
    public function translate(?string $text): ?string
    {
        if ($text === null || $text === '' || $this->isPrimary()) {
            return $text;
        }

        return str_replace(
            ['Pupils', 'pupils', 'Pupil', 'pupil'],
            ['Students', 'students', 'Student', 'student'],
            $text,
        );
    }
}
