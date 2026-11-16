<?php

namespace App\Tests\Twig;

use App\Twig\ErrorExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An error a school can read is an error a school can fix.
 *
 * The rule these tests hold: a sentence the application wrote on purpose reaches
 * the person holding the phone; framework noise and internal detail never do.
 */
final class ErrorExtensionTest extends TestCase
{
    private ErrorExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new ErrorExtension();
    }

    public function testADeliberateMessageIsShown(): void
    {
        $exception = new NotFoundHttpException(
            'The active term has no start or end date, so there is no school calendar.'
        );

        self::assertSame(
            'The active term has no start or end date, so there is no school calendar.',
            $this->extension->reason($exception)
        );
    }

    public function testTheProductionFlattenExceptionIsUnderstood(): void
    {
        // TwigErrorRenderer hands the template a FlattenException, not a Throwable.
        // Missing this is exactly how the reason block first rendered empty.
        $flattened = FlattenException::createFromThrowable(
            new AccessDeniedHttpException('You are not a form master for the active term, so you have no class to look after.')
        );

        self::assertSame(
            'You are not a form master for the active term, so you have no class to look after.',
            $this->extension->reason($flattened)
        );
    }

    public function testFrameworkNoiseIsNeverShown(): void
    {
        self::assertNull($this->extension->reason(
            new NotFoundHttpException('No route found for "GET http://demo.localhost:8082/nope"')
        ));

        self::assertNull($this->extension->reason(
            new AccessDeniedHttpException('Invalid CSRF token.')
        ));

        self::assertNull($this->extension->reason(
            new \RuntimeException('SQLSTATE[42S02]: Base table or view not found')
        ));
    }

    public function testInternalDetailIsNeverShown(): void
    {
        // A namespace, a file path or a line number is for the log, not the parent.
        self::assertNull($this->extension->reason(
            new \RuntimeException('Call to undefined method App\\Entity\\Tenant\\Student::foo() in /app/src/Foo.php:42')
        ));
    }

    public function testSomethingThatIsNotAnExceptionIsIgnored(): void
    {
        self::assertNull($this->extension->reason(null));
        self::assertNull($this->extension->reason('a string'));
        self::assertNull($this->extension->reference(null));
    }

    public function testTheReferenceIsStableForTheSameFailure(): void
    {
        $first = $this->extension->reference(
            new NotFoundHttpException('The active term has no start or end date, so there is no school calendar.'),
            '/my-class'
        );
        $second = $this->extension->reference(
            new NotFoundHttpException('The active term has no start or end date, so there is no school calendar.'),
            '/my-class'
        );
        $other = $this->extension->reference(
            new NotFoundHttpException('The active term has no start or end date, so there is no school calendar.'),
            '/my-class/attendance'
        );

        self::assertSame($first, $second);
        self::assertNotSame($first, $other);
        self::assertMatchesRegularExpression('/^ED-[0-9A-F]{6}$/', (string) $first);
    }
}
