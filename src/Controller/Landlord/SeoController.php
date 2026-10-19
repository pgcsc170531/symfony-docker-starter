<?php

namespace App\Controller\Landlord;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Machine-readable pages for the public marketing site.
 *
 * Both routes are pinned to the base domain on purpose: a school's subdomain
 * must not advertise Edus's own marketing pages in its sitemap. Tenant
 * sitemaps/robots are a separate job (docs/websites/SCHOOL-PUBLIC-SITE.md §7).
 */
class SeoController extends AbstractController
{
    /** Public pages worth indexing, in priority order. */
    private const PAGES = [
        ['route' => 'app_home', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['route' => 'app_pricing', 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['route' => 'app_features', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'app_manual', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ['route' => 'app_partners', 'changefreq' => 'monthly', 'priority' => '0.5'],
    ];

    /** Bump when marketing copy changes materially, not on every deploy. */
    private const LAST_MODIFIED = '2026-10-18';

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], host: '%app.base_domain%')]
    public function sitemap(): Response
    {
        $urls = [];

        foreach (self::PAGES as $page) {
            $urls[] = [
                'loc' => $this->generateUrl($page['route'], [], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
                'lastmod' => self::LAST_MODIFIED,
            ];
        }

        $response = $this->render('landlord/seo/sitemap.xml.twig', ['urls' => $urls]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'], host: '%app.base_domain%')]
    public function robots(): Response
    {
        $response = $this->render('landlord/seo/robots.txt.twig', [
            'sitemap' => $this->generateUrl('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return $response;
    }
}
