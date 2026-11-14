<?php

namespace App\Tests\Service\Finance;

use App\Entity\Tenant\Term;
use App\Service\Finance\FeeImportService;
use PHPUnit\Framework\TestCase;

/**
 * The CSV reader is the part a bursar meets first.
 *
 * A header that is not recognised rejects the whole file, so these tests pin the
 * alias mapping and the row-level errors that must not abort the rest of the
 * import.
 */
final class FeeImportServiceParseTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    public function test_common_header_spellings_are_recognised(): void
    {
        $result = $this->parse(<<<'CSV'
Admission No,Surname,First Name,Class,Type,Amount Paid,Date,Method,Reference,Remark
ADM/1,Bello,Aisha,JSS 1A,NEW,25000,14/09/2026,CASH,C-1,First instalment
CSV);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);

        $row = $result['rows'][0];
        self::assertSame('ADM/1', $row['admission_number']);
        self::assertSame('NEW', $row['student_type']);
        self::assertSame(25000.0, $row['amount_paid']);
        self::assertSame('2026-09-14', $row['payment_date']);
        self::assertSame('CASH', $row['payment_method']);
    }

    public function test_an_amount_with_a_thousands_separator_is_a_row_error_not_a_file_error(): void
    {
        $result = $this->parse(<<<'CSV'
Admission Number,Surname,First Name,Class Name,Student Type,Amount Paid
ADM/1,Bello,Aisha,JSS 1A,NEW,"25,000"
ADM/2,Bello,Musa,JSS 1A,RETURNING,52500
CSV);

        self::assertCount(2, $result['rows'], 'a bad row must not lose the good one');
        self::assertNotSame([], $result['rows'][0]['errors']);
        self::assertStringContainsString('thousands separator', implode(' ', $result['rows'][0]['errors']));
        self::assertSame([], $result['rows'][1]['errors']);
    }

    public function test_an_unknown_student_type_is_reported(): void
    {
        $result = $this->parse(<<<'CSV'
Admission Number,Surname,First Name,Class Name,Student Type,Amount Paid
ADM/1,Bello,Aisha,JSS 1A,OLD BOY,0
CSV);

        self::assertStringContainsString('not NEW or RETURNING', implode(' ', $result['rows'][0]['errors']));
    }

    public function test_a_blank_amount_means_zero(): void
    {
        $result = $this->parse(<<<'CSV'
Admission Number,Surname,First Name,Class Name,Student Type,Amount Paid
ADM/1,Bello,Aisha,JSS 1A,NEW,
CSV);

        self::assertSame(0.0, $result['rows'][0]['amount_paid']);
    }

    public function test_a_file_missing_a_required_column_is_rejected_whole(): void
    {
        $result = $this->parse(<<<'CSV'
Admission Number,Surname,First Name,Class Name,Student Type
ADM/1,Bello,Aisha,JSS 1A,NEW
CSV);

        self::assertSame([], $result['rows']);
        self::assertStringContainsString('missing', implode(' ', $result['errors']));
        self::assertStringContainsString('Amount Paid', implode(' ', $result['errors']));
    }

    /** @return array{rows: list<array<string, mixed>>, errors: list<string>, repaired: int} */
    private function parse(string $csv): array
    {
        $path = tempnam(sys_get_temp_dir(), 'fee-import-');
        self::assertNotFalse($path);
        file_put_contents($path, $csv);
        $this->files[] = $path;

        $reflection = new \ReflectionClass(FeeImportService::class);
        /** @var FeeImportService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        return $service->parse($path, new Term());
    }
}
