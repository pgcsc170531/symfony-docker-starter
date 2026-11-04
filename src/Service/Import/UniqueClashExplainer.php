<?php

namespace App\Service\Import;

use App\Service\Phone\PhoneNumber;

/**
 * Turns a raw `Duplicate entry … for key …` into a sentence the office can act on.
 *
 * A quick-admission upload that hits a unique index used to stop with
 * "A row in your file clashes with a record that already exists" — no row, no name,
 * no class, in a file that may be 145 lines long. Everything needed to find the
 * culprit is already in the database's message and in the rows we tried to save;
 * this class puts the two together.
 *
 * The index name is deliberately NOT trusted: MySQL says
 * `student.UNIQ_B723AF33AC03CB1E`, which names no column at all. What the value
 * looks like — and which row in the file carries it — is the reliable signal.
 *
 * Kept out of the controller so it can be tested without a database.
 */
final class UniqueClashExplainer
{
    /**
     * @param list<array{data: array<string, string>, classroom: object, row: int, name: string}> $rows
     */
    public function explain(string $sqlMessage, array $rows): string
    {
        if (preg_match("/Duplicate entry '(.+?)' for key '(.+?)'/i", $sqlMessage, $matches) !== 1) {
            return 'Upload Stopped — nothing was imported. A row in your file clashes with a record that already '
                . 'exists. Check the admission numbers and the parent phone numbers, then upload again.';
        }

        return $this->describe($matches[1], $matches[2], $rows);
    }

    /**
     * @param list<array{data: array<string, string>, classroom: object, row: int, name: string}> $rows
     */
    public function describe(string $value, string $key, array $rows): string
    {
        $culprit = $this->findRow($value, $rows);
        $field = $culprit['field'] ?? $this->guessField($value);

        [$what, $advice] = match ($field) {
            'phone' => [
                'parent phone (it becomes the parent login)',
                'That parent already has an account under another spelling of the same number. Use one phone number for the family.',
            ],
            'admission' => [
                'admission number',
                'Give that pupil a different admission number, or delete the row if the pupil is already on roll.',
            ],
            default => [
                'value',
                sprintf('Correct that value in the file and upload again.%s', $key !== '' ? ' (database key: ' . $key . ')' : ''),
            ],
        };

        if ($culprit === null) {
            return sprintf(
                'Upload Stopped — nothing was imported. The %s "%s" is already used by another record in this school. %s',
                $what,
                $value,
                $advice
            );
        }

        return sprintf(
            'Upload Stopped — nothing was imported. Row %d, %s (%s): the %s "%s" is already used by another record '
            . 'in this school. %s',
            $culprit['row'],
            $culprit['name'] !== '' ? $culprit['name'] : 'unnamed pupil',
            $culprit['classroom']->getName(),
            $what,
            $value,
            $advice
        );
    }

    /**
     * The row in this file that carried the duplicated value.
     *
     * Admission numbers and parent phone numbers are the only two things in a
     * quick-admission file that can collide with a row already in the database.
     *
     * @param list<array{data: array<string, string>, classroom: object, row: int, name: string}> $rows
     *
     * @return array{data: array<string, string>, classroom: object, row: int, name: string, field: string}|null
     */
    private function findRow(string $value, array $rows): ?array
    {
        $digits = PhoneNumber::national($value);

        foreach ($rows as $item) {
            $data = $item['data'];
            $phone = (string) ($data['parent_phone'] ?? '');

            if ($value === (string) ($data['admission_number'] ?? '')) {
                return $item + ['field' => 'admission'];
            }

            if ($phone !== ''
                && ($value === $phone
                    || ($digits !== '' && $digits === $phone)
                    || strcasecmp($value, PhoneNumber::loginEmail($phone)) === 0)) {
                return $item + ['field' => 'phone'];
            }
        }

        return null;
    }

    /**
     * When no row in the file carries the value, guess the field from its shape.
     *
     * The clash then came from a record the importer itself created while matching
     * a parent, so the office still needs to know what kind of value to look for.
     */
    private function guessField(string $value): string
    {
        if (str_contains($value, '@')) {
            return 'phone';
        }

        return ctype_digit($value) ? 'phone' : 'admission';
    }
}
