<?php

namespace App\Tests\Service\Import;

use App\Entity\Tenant\Classroom;
use App\Service\Import\UniqueClashExplainer;
use PHPUnit\Framework\TestCase;

/**
 * What the office is told when an upload collides with an existing record.
 *
 * The bug this locks down, seen in production:
 *
 *     Upload Stopped! A row in your file clashes with a record that already
 *     exists (a guardian phone/email or a pupil already on roll)…
 *
 * — no row number, no name, no class, in a file that can be 145 lines long. MySQL
 * had already said which value collided; nothing was translating it back.
 */
final class UniqueClashExplainerTest extends TestCase
{
    private UniqueClashExplainer $explainer;

    protected function setUp(): void
    {
        $this->explainer = new UniqueClashExplainer();
    }

    public function test_an_admission_number_clash_names_the_row_the_pupil_and_the_class(): void
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'P23/077' "
            . "for key 'student.UNIQ_B723AF33AC03CB1E'";

        $sentence = $this->explainer->explain($message, [
            $this->row(2, 'ABDULRAHMAN BASHIR YAU', 'P23/078', '08136302793', 'Primary 4 NEEM'),
            $this->row(7, 'AISHA IBRAHIM SANI', 'P23/077', '08031531054', 'Primary 4 NEEM'),
        ]);

        self::assertStringContainsString('Row 7', $sentence);
        self::assertStringContainsString('AISHA IBRAHIM SANI', $sentence);
        self::assertStringContainsString('Primary 4 NEEM', $sentence);
        self::assertStringContainsString('P23/077', $sentence);
        self::assertStringContainsString('admission number', $sentence);
    }

    public function test_a_parent_phone_clash_names_the_row_by_its_phone(): void
    {
        // The duplicate is the login address derived from the phone, not the number.
        $message = "Duplicate entry '8031531054@edus.ng' for key 'user.UNIQ_8D93D649E7927C74'";

        $sentence = $this->explainer->explain($message, [
            $this->row(2, 'ABDULRAHMAN BASHIR YAU', 'P23/078', '08136302793', 'Primary 4 NEEM'),
            $this->row(9, 'AISHA IBRAHIM SANI', 'P23/093', '08031531054', 'Primary 4 NEEM'),
        ]);

        self::assertStringContainsString('Row 9', $sentence);
        self::assertStringContainsString('AISHA IBRAHIM SANI', $sentence);
        self::assertStringContainsString('parent phone', $sentence);
    }

    public function test_a_clash_with_no_matching_row_still_says_what_collided(): void
    {
        $message = "Duplicate entry 'ZZ/2026/0001' for key 'student.UNIQ_B723AF33AC03CB1E'";

        $sentence = $this->explainer->explain($message, [
            $this->row(2, 'ABDULRAHMAN BASHIR YAU', 'P23/078', '08136302793', 'Primary 4 NEEM'),
        ]);

        self::assertStringContainsString('ZZ/2026/0001', $sentence);
        self::assertStringContainsString('admission number', $sentence);
        self::assertStringNotContainsString('Row 2', $sentence, 'a row that does not carry the value must not be blamed');
    }

    public function test_an_unreadable_message_falls_back_to_a_sentence_not_sql(): void
    {
        $sentence = $this->explainer->explain('something the driver said', []);

        self::assertStringContainsString('nothing was imported', $sentence);
        self::assertStringNotContainsString('SQLSTATE', $sentence);
    }

    public function test_a_row_with_no_name_is_still_identified(): void
    {
        $message = "Duplicate entry 'P23/077' for key 'student.UNIQ_B723AF33AC03CB1E'";

        $sentence = $this->explainer->explain($message, [
            $this->row(4, '', 'P23/077', '08030000001', 'Primary 5 CHERRY'),
        ]);

        self::assertStringContainsString('Row 4', $sentence);
        self::assertStringContainsString('unnamed pupil', $sentence);
        self::assertStringContainsString('Primary 5 CHERRY', $sentence);
    }

    /** @return array{data: array<string, string>, classroom: Classroom, row: int, name: string} */
    private function row(int $number, string $name, string $admission, string $phone, string $classroom): array
    {
        $room = (new Classroom())->setName($classroom);

        return [
            'data' => [
                'admission_number' => $admission,
                'parent_phone' => $phone,
                'parent_name' => 'PARENT',
            ],
            'classroom' => $room,
            'row' => $number,
            'name' => $name,
        ];
    }
}
