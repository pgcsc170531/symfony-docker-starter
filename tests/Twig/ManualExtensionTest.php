<?php

namespace App\Tests\Twig;

use App\Twig\ManualExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The "?" in the app header must open the chapter for the screen you are on —
 * and must not exist at all where there is no chapter. Prefix order is the whole
 * trick: `app_tenant_report_student` is a results screen while
 * `app_tenant_report_debtors` is a fee report, and both start the same way.
 */
final class ManualExtensionTest extends TestCase
{
    private function extension(): ManualExtension
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://edus.ng/manual');

        return new ManualExtension($urls);
    }

    public function testResultsReportsReachTheResultsChapterNotFeeReporting(): void
    {
        $extension = $this->extension();

        self::assertSame('section-10', $extension->manualHelp('app_tenant_report_student')['chapter']);
        self::assertSame('section-10', $extension->manualHelp('app_tenant_report_form_teacher')['chapter']);
    }

    public function testFeeReportsReachTheFeeChapter(): void
    {
        $extension = $this->extension();

        self::assertSame('section-12', $extension->manualHelp('app_tenant_report_debtors')['chapter']);
        self::assertSame('section-12', $extension->manualHelp('app_tenant_report_statement_pdf')['chapter']);
    }

    public function testEverydayScreensMapToTheirChapter(): void
    {
        $extension = $this->extension();

        $expected = [
            'app_tenant_dashboard'          => 'section-1',
            'app_tenant_student_index'      => 'section-6',
            'app_tenant_admission_new'      => 'section-3',
            'app_tenant_payment_terminal'   => 'section-5',
            'app_tenant_product_index'      => 'section-4',
            'app_tenant_expense_index'      => 'section-7',
            'app_tenant_subscription_index' => 'section-8',
            'app_tenant_classroom_index'    => 'section-9',
            'app_tenant_result_entry_index' => 'section-10',
            'app_tenant_my_class_attendance' => 'section-11',
            'app_parent_finance_view'       => 'section-13',
            'tenant_fee_report_verify'      => 'section-14',
        ];

        foreach ($expected as $route => $chapter) {
            self::assertSame($chapter, $extension->manualHelp($route)['chapter'] ?? null, $route);
        }
    }

    public function testFirstLoginSettingsWinsOverTheDashboardPrefix(): void
    {
        // `app_tenant_dashboard_settings` starts with `app_tenant_dashboard`, so a
        // careless order would send staff to chapter 1 instead of chapter 8.
        self::assertSame('section-8', $this->extension()->manualHelp('app_tenant_dashboard_settings')['chapter']);
    }

    public function testUnprefixedTenantRoutesAreCovered(): void
    {
        // `tenant_subscription_index`, `tenant_result_entry_index` and
        // `tenant_approval_hub` do not start with `app_` — a map with only the
        // `app_` spelling silently leaves the help icon off those screens.
        $extension = $this->extension();

        self::assertSame('section-8', $extension->manualHelp('tenant_subscription_index')['chapter']);
        self::assertSame('section-8', $extension->manualHelp('tenant_wallet_index')['chapter']);
        self::assertSame('section-10', $extension->manualHelp('tenant_result_entry_index')['chapter']);
        self::assertSame('section-10', $extension->manualHelp('tenant_approval_hub')['chapter']);
        self::assertSame('section-14', $extension->manualHelp('tenant_statement_verify')['chapter']);
    }

    public function testUrlIsAbsoluteAndAnchored(): void
    {
        $help = $this->extension()->manualHelp('app_tenant_report_debtors');

        self::assertSame('https://edus.ng/manual#section-12', $help['url']);
    }

    public function testUnknownAndMissingRoutesHaveNoHelp(): void
    {
        $extension = $this->extension();

        self::assertNull($extension->manualHelp(null));
        self::assertNull($extension->manualHelp(''));
        self::assertNull($extension->manualHelp('app_landlord_home'));
    }
}
