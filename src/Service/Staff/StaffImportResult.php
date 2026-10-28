<?php

namespace App\Service\Staff;

/**
 * What a staff CSV upload did, in numbers and in plain sentences.
 *
 * The office uploads a file and walks away, so the result has to answer three
 * questions without them opening the database: how many accounts were made,
 * which rows were left alone, and why.
 */
final class StaffImportResult
{
    public int $rows = 0;
    public int $created = 0;

    /** @var list<string> One sentence per skipped row, already row-numbered. */
    public array $skipped = [];

    public function skip(int $rowNumber, string $reason): void
    {
        $this->skipped[] = sprintf('Row %d: %s', $rowNumber, $reason);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
