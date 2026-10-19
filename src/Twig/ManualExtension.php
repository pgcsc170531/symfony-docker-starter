<?php

namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ manual_help(app.request.attributes.get('_route')) }}` — the contextual
 * "?" in the app header.
 *
 * The manual is published on the platform domain (`app_manual`, host
 * `%app.base_domain%`), while the app runs on a school's subdomain, so a plain
 * `path()` from the app would point at a route that host does not serve. The URL
 * is generated as absolute and the chapter anchor appended here, once, so no
 * screen has to know where the manual lives or which chapter it belongs to.
 *
 * Prefix matching, longest-first: `app_tenant_report_student` must reach the
 * results chapter while `app_tenant_report_debtors` reaches fee reporting, and
 * both start with `app_tenant_report_`.
 */
final class ManualExtension extends AbstractExtension
{
    /**
     * Route prefix => manual chapter anchor.
     *
     * Keep this ordered most-specific first; the first match wins. Adding a
     * screen here is the whole job — never hard-code a manual URL in a template.
     */
    private const CHAPTERS = [
        // Results & approvals (Chapter 10) — must precede the generic report rule.
        // Some tenant controllers name their routes without the `app_` prefix
        // (`tenant_result_entry_*`, `tenant_approval_*`), so both spellings are here.
        'app_tenant_result_entry'           => 'section-10',
        'tenant_result_entry'               => 'section-10',
        'app_tenant_approval'               => 'section-10',
        'tenant_approval'                   => 'section-10',
        'app_tenant_term_remark'            => 'section-10',
        'app_tenant_report_student'         => 'section-10',
        'app_tenant_report_teacher'         => 'section-10',
        'app_tenant_report_form_teacher'    => 'section-10',
        'app_tenant_report_hod'             => 'section-10',
        'app_tenant_report_year_group'      => 'section-10',
        'app_tenant_report_admin'           => 'section-10',
        'app_tenant_report_class_print'     => 'section-10',
        'app_tenant_report_performance'     => 'section-10',
        'app_tenant_report_archive'         => 'section-10',

        // Attendance & behaviour (Chapter 11).
        'app_tenant_my_class'               => 'section-11',

        // Fee reporting suite (Chapter 12).
        'app_tenant_report_'                => 'section-12',

        // Verification pages (Chapter 14).
        'app_tenant_verify'                 => 'section-14',
        'tenant_fee_report_verify'          => 'section-14',
        'tenant_statement_verify'           => 'section-14',

        // Store (Chapter 4).
        'app_tenant_product'                => 'section-4',
        'app_tenant_store'                  => 'section-4',
        'app_tenant_sale'                   => 'section-4',

        // Payments & verification (Chapter 5).
        'app_tenant_payment'                => 'section-5',

        // Directory, profiles, promotion (Chapter 6).
        'app_tenant_student'                => 'section-6',

        // Expenses & profit and loss (Chapter 7).
        'app_tenant_expense'                => 'section-7',

        // Subscription, wallet, settings (Chapter 8).
        'app_tenant_subscription'           => 'section-8',
        'tenant_subscription'               => 'section-8',
        'app_tenant_wallet'                 => 'section-8',
        'tenant_wallet'                     => 'section-8',
        'app_tenant_settings'               => 'section-8',
        'app_tenant_dashboard_settings'     => 'section-8',

        // Academic structure & assignments (Chapter 9).
        'app_tenant_academic'               => 'section-9',
        'app_tenant_classroom'              => 'section-9',
        'app_tenant_year_group'             => 'section-9',
        'app_tenant_department'             => 'section-9',
        'app_tenant_subject'                => 'section-9',
        'app_tenant_class_subject'          => 'section-9',
        'app_tenant_grading_scale'          => 'section-9',
        'app_tenant_staff'                  => 'section-9',
        'app_tenant_enrollment'             => 'section-9',
        'app_tenant_behaviour_trait'        => 'section-9',

        // Parent portal & online applications (Chapter 13).
        'app_parent'                        => 'section-13',
        'app_tenant_apply'                  => 'section-13',

        // Admissions, billing and the fee screens (Chapter 3).
        'app_tenant_admission'              => 'section-3',
        'app_tenant_invoice'                => 'section-3',
        'app_tenant_fee'                    => 'section-3',
        'app_tenant_discount'               => 'section-3',
        'app_tenant_online_payment'         => 'section-3',

        // Migration / bulk import (Chapter 2).
        'app_tenant_quick_enroll'           => 'section-2',
        'app_tenant_import'                 => 'section-2',

        // Dashboard (Chapter 1).
        'app_tenant_dashboard'              => 'section-1',
    ];

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('manual_help', $this->manualHelp(...)),
        ];
    }

    /**
     * @return array{chapter: string, url: string}|null  null when there is no
     *         manual page for this route (the header then shows no "?").
     */
    public function manualHelp(?string $route): ?array
    {
        if ($route === null || $route === '') {
            return null;
        }

        foreach (self::CHAPTERS as $prefix => $chapter) {
            if (str_starts_with($route, $prefix)) {
                return [
                    'chapter' => $chapter,
                    'url'     => $this->urls->generate('app_manual', [], UrlGeneratorInterface::ABSOLUTE_URL)
                        . '#' . $chapter,
                ];
            }
        }

        return null;
    }
}
