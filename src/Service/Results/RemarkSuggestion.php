<?php

namespace App\Service\Results;

/**
 * Generated remark suggestions, keyed to how the term actually went.
 *
 * The form teacher edits the text; they never start from an empty box. Typing 41
 * paragraphs is why teachers abandon report systems (RESULT-CARD-DESIGN.md §11
 * decision 9). The language is deliberately plain, positive where it can be and
 * honest where it must be.
 */
final class RemarkSuggestion
{
    public function for(?string $firstName, ?float $percentage): string
    {
        $name = trim((string) $firstName);
        $name = $name !== '' ? $name : 'This pupil';
        $possessive = $name === 'This pupil' ? 'Their' : $name . "'s";

        $opening = match (true) {
            $percentage === null => 'has worked steadily this term',
            $percentage >= 75 => 'has had an excellent term',
            $percentage >= 70 => 'has had a very good term',
            $percentage >= 65 => 'has had a good term',
            $percentage >= 60 => 'has had a solid term',
            $percentage >= 50 => 'has had a fair term',
            $percentage >= 40 => 'has found the term difficult',
            default => 'has struggled this term',
        };

        $closing = match (true) {
            $percentage === null => 'Keep encouraging steady effort.',
            $percentage >= 70 => 'Keep up the excellent work.',
            $percentage >= 60 => 'A little more consistency will take the results higher.',
            $percentage >= 50 => 'More regular revision will make a real difference.',
            $percentage >= 40 => 'Focused support at home and in class is needed.',
            default => 'Please meet the form teacher to agree a support plan.',
        };

        return sprintf('%s %s. %s', $name === 'This pupil' ? 'This pupil' : $name, $opening, $closing);
    }
}
