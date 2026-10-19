<?php

namespace App\Tests\Service\Finance;

use App\Entity\Tenant\Session;
use App\Service\Finance\InvoiceNumberGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * One series, readable over the phone: INV-2026-0001.
 *
 * The bugs this locks down were all format bugs — five screens used to mint
 * `INV-<hex>`, `INV-2026-<hex>` and `INV-AUTO-<hex>` — plus the bulk-import trap
 * where hundreds of invoices are created before anything is flushed.
 */
final class InvoiceNumberGeneratorTest extends TestCase
{
    private function generator(array $existing = [], int $year = 2026): InvoiceNumberGenerator
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn($existing);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new InvoiceNumberGenerator($em);
    }

    public function testStartsAtOneAndPadsToFourDigits(): void
    {
        self::assertSame('INV-2026-0001', $this->generator()->next(2026));
    }

    public function testContinuesFromTheHighestNumberInTheSeries(): void
    {
        $generator = $this->generator(['INV-2026-0007', 'INV-2026-0003']);

        self::assertSame('INV-2026-0008', $generator->next(2026));
    }

    public function testLegacyHexNumbersInTheSamePrefixAreIgnoredNotMisread(): void
    {
        // Old rows look like INV-2026-6a968cb422cea. Reading that as a sequence
        // would produce a 13-digit "number"; skipping it keeps 4 digits.
        $generator = $this->generator(['INV-2026-6a968cb422cea', 'INV-2026-0002']);

        self::assertSame('INV-2026-0003', $generator->next(2026));
    }

    public function testBulkImportInOneProcessNeverRepeatsANumber(): void
    {
        // The database is not flushed between calls, so a generator that only
        // read MAX() would hand out INV-2026-0001 five times and the unique
        // index would reject the import half way through.
        $generator = $this->generator(['INV-2026-0001']);

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $generator->next(2026);
        }

        self::assertSame(
            ['INV-2026-0002', 'INV-2026-0003', 'INV-2026-0004', 'INV-2026-0005', 'INV-2026-0006'],
            $numbers
        );
        self::assertCount(5, array_unique($numbers));
    }

    public function testYearsDoNotShareASequence(): void
    {
        $generator = $this->generator(['INV-2026-0009']);

        self::assertSame('INV-2026-0010', $generator->next(2026));
        self::assertSame('INV-2027-0001', $generator->next(2027));
    }

    public function testYearComesFromTheSessionNotTheCalendar(): void
    {
        $session = (new Session())->setName('2027/2028');

        // Creating next session's invoices in December must not produce INV-2026.
        self::assertSame(2027, $this->generator()->yearFor($session));
    }

    public function testYearFallsBackToTodayWhenTheSessionIsUnnamed(): void
    {
        self::assertSame(2026, $this->generator()->yearFor(null, new \DateTimeImmutable('2026-10-19')));
        self::assertSame(2026, $this->generator()->yearFor((new Session())->setName('First Term'), new \DateTimeImmutable('2026-10-19')));
    }
}
