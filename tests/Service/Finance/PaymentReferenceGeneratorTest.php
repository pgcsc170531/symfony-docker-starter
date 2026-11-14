<?php

namespace App\Tests\Service\Finance;

use App\Service\Finance\PaymentReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A bulk import creates several payments before the first flush.
 *
 * The generator used to return the same number on the second call, which the
 * unique index rejected and took the whole import down with it.
 */
final class PaymentReferenceGeneratorTest extends TestCase
{
    public function test_consecutive_calls_in_one_process_are_unique(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willThrowException(new \RuntimeException('no database in this test'));

        $generator = new PaymentReferenceGenerator($em);

        $first = $generator->next();
        $second = $generator->next();
        $third = $generator->next();

        self::assertNotSame($first, $second);
        self::assertNotSame($second, $third);
        self::assertNotSame($first, $third);
        self::assertSame((int) $first + 1, (int) $second);
        self::assertSame((int) $second + 1, (int) $third);
    }
}
