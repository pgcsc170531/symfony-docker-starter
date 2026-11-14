<?php

namespace App\Tests\Service\Students;

use App\Entity\Tenant\Student;
use App\Service\Students\FamilyLinker;
use PHPUnit\Framework\TestCase;

/**
 * The evidence rule that decides whether two parent records are one family.
 *
 * Two families can share a handset, so matching the phone alone would merge
 * strangers. This locks down the narrower rule the school asked for: a shared
 * surname, or a shared **non-empty** middle name.
 */
final class FamilyLinkerTest extends TestCase
{
    public function test_a_shared_surname_is_enough(): void
    {
        $label = FamilyLinker::nameMatch(
            $this->student('Aisha', 'Ibrahim', 'Bello'),
            $this->student('Musa', null, 'Bello')
        );

        self::assertSame('surname “Bello”', $label);
    }

    public function test_a_shared_middle_name_is_enough_when_surnames_differ(): void
    {
        $label = FamilyLinker::nameMatch(
            $this->student('Aisha', 'Ibrahim', 'Bello'),
            $this->student('Musa', 'ibrahim ', 'Sani')
        );

        self::assertSame('middle name “Ibrahim”', $label);
    }

    public function test_names_are_compared_without_case_or_extra_spaces(): void
    {
        $label = FamilyLinker::nameMatch(
            $this->student('Aisha', null, '  BELLO '),
            $this->student('Musa', null, 'bello')
        );

        self::assertSame('surname “BELLO”', $label);
    }

    public function test_two_pupils_with_no_middle_name_do_not_match_on_it(): void
    {
        self::assertNull(FamilyLinker::nameMatch(
            $this->student('Aisha', null, 'Bello'),
            $this->student('Musa', null, 'Sani')
        ));
    }

    public function test_a_pupil_with_no_middle_name_never_matches_another_by_blank(): void
    {
        self::assertNull(FamilyLinker::nameMatch(
            $this->student('Aisha', '', 'Bello'),
            $this->student('Musa', '', 'Sani')
        ));
    }

    private function student(string $first, ?string $middle, string $last): Student
    {
        return (new Student())
            ->setFirstName($first)
            ->setMiddleName($middle)
            ->setLastName($last);
    }
}
