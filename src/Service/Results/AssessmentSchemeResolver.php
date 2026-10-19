<?php

namespace App\Service\Results;

use App\Entity\Tenant\AssessmentScheme;
use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that decides a subject's CA/Exam maxima.
 *
 * The score-entry screen, the report card, the trend strip and the analytics
 * brief must all agree. The fallback chain is: subject-specific → class-wide →
 * school-wide → defaults.
 */
final class AssessmentSchemeResolver
{
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

        return [
            'ca_count' => $scheme?->getCaCount() ?? 3,
            'exam_mode' => $scheme?->getExamMode() ?? AssessmentScheme::EXAM_MODE_SINGLE,
            'ca_max' => $scheme?->getCaMax() ?? 20,
            'theory_max' => $scheme?->getTheoryMax() ?? 60,
            'practical_max' => $scheme?->getPracticalMax() ?? 40,
        ];
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
