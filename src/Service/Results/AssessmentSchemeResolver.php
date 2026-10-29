<?php

namespace App\Service\Results;

use App\Entity\Tenant\AssessmentScheme;
use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that decides a subject's CA/Exam maxima, and which components
 * count towards a total.
 *
 * The score-entry screen, the report card, the trend strip and the analytics
 * brief must all agree. The fallback chain is: subject-specific → class-wide →
 * school-wide → the school's own CA count from Settings.
 */
final class AssessmentSchemeResolver
{
    /** Loaded on demand: one request may score a whole class, but there is one school. */
    private ?int $schoolCaCount = null;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{ca_count: int, exam_mode: string, ca_max: int, theory_max: int, practical_max: int}
     */
    public function resolve(Classroom $classroom, ClassSubject $classSubject, Term $term): array
    {
        $repo = $this->em->getRepository(AssessmentScheme::class);
        $subject = $classSubject->getSubject();

        $scheme = $repo->findOneBy(['term' => $term, 'classroom' => $classroom, 'subject' => $subject])
            ?? $repo->findOneBy(['term' => $term, 'classroom' => $classroom, 'subject' => null])
            ?? $repo->findOneBy(['term' => $term, 'classroom' => null, 'subject' => null]);

        if ($scheme === null) {
            return $this->defaults();
        }

        return [
            'ca_count' => $this->normaliseCaCount($scheme->getCaCount()),
            'exam_mode' => $scheme->getExamMode() ?? AssessmentScheme::EXAM_MODE_SINGLE,
            'ca_max' => $scheme->getCaMax() ?? 20,
            'theory_max' => $scheme->getTheoryMax() ?? 60,
            'practical_max' => $scheme->getPracticalMax() ?? 40,
        ];
    }

    /**
     * The scheme a school gets when nothing is configured anywhere: its own CA
     * count from Settings, with the usual maxima.
     *
     * @return array{ca_count: int, exam_mode: string, ca_max: int, theory_max: int, practical_max: int}
     */
    public function defaults(): array
    {
        return [
            'ca_count' => $this->defaultCaCount(),
            'exam_mode' => AssessmentScheme::EXAM_MODE_SINGLE,
            'ca_max' => 20,
            'theory_max' => 60,
            'practical_max' => 40,
        ];
    }

    /**
     * How many CA tests this school says a subject carries — the general setting,
     * read once per request.
     */
    public function defaultCaCount(): int
    {
        if ($this->schoolCaCount === null) {
            $school = $this->em->getRepository(School::class)->findOneBy([]);
            $this->schoolCaCount = $this->normaliseCaCount($school?->getCaCount());
        }

        return $this->schoolCaCount;
    }

    /**
     * The component keys that count for a scheme, in the order they are shown.
     *
     * A school that moved from three CAs to two loses `ca3` here — and with it the
     * field on the entry sheet, the column on the report card and the mark in the
     * total.
     *
     * @param array{ca_count: int, exam_mode: string} $scheme
     *
     * @return list<string>
     */
    public function components(array $scheme): array
    {
        $components = [];
        for ($i = 1; $i <= $scheme['ca_count']; $i++) {
            $components[] = 'ca' . $i;
        }

        $components[] = 'theory';
        if ($scheme['exam_mode'] === AssessmentScheme::EXAM_MODE_DUAL) {
            $components[] = 'practical';
        }

        return $components;
    }

    /**
     * Sums only the components the scheme counts.
     *
     * Marks already in the database for a dropped component are kept but never
     * added in — a school that went from three CAs to two would otherwise rank
     * pupils on a test it no longer reports.
     *
     * @param array{ca_count: int, exam_mode: string} $scheme
     * @param array<string, float|int|string>         $components
     */
    public function total(array $scheme, array $components): float
    {
        $sum = 0.0;
        foreach ($this->components($scheme) as $key) {
            $sum += (float) ($components[$key] ?? 0);
        }

        return $sum;
    }

    private function normaliseCaCount(?int $count): int
    {
        return in_array($count, [AssessmentScheme::CA_COUNT_TWO, AssessmentScheme::CA_COUNT_THREE], true)
            ? (int) $count
            : AssessmentScheme::CA_COUNT_THREE;
    }

    /**
     * @param array{ca_count: int, exam_mode: string, ca_max: int, theory_max: int, practical_max: int} $scheme
     */
    public function maximum(array $scheme): float
    {
        return (float) ($scheme['ca_max'] * $scheme['ca_count']
            + $scheme['theory_max']
            + ($scheme['exam_mode'] === AssessmentScheme::EXAM_MODE_DUAL ? $scheme['practical_max'] : 0));
    }
}
