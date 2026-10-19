<?php

namespace App\Service\Results;

use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The optional fee gate on report cards.
 *
 * Off by default: most schools want the academic record to reach the parent.
 * When a school turns it on, a report is withheld server-side — the screen,
 * the PDF and any share link all obey it. The page explains what is happening;
 * it is never a bare 403.
 */
final class ReportAccessGate
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function school(): ?School
    {
        return $this->em->getRepository(School::class)->findOneBy([]);
    }

    public function outstanding(Student $student): float
    {
        return round($student->getOutstandingBalance(), 2);
    }

    public function isWithheld(Student $student, ?School $school = null): bool
    {
        $school ??= $this->school();

        if (!$school?->isReportFeeGateEnabled()) {
            return false;
        }

        return $this->outstanding($student) > 0.005;
    }
}
