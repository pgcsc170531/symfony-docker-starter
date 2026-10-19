<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\School;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\YearGroup;
use App\Service\Results\PerformanceReportCode;
use App\Service\Results\SchoolPerformanceBuilder;
use App\Service\Results\SchoolPerformancePdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The short URL printed in the performance PDF's QR.
 *
 * The code is an HMAC of the term and year group, so it stays valid after the
 * 30-day share link has expired. It returns the aggregate PDF only.
 */
#[Route('/pv')]
final class PerformanceVerificationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SchoolPerformanceBuilder $builder,
        private SchoolPerformancePdfRenderer $pdfRenderer,
        private PerformanceReportCode $codes,
    ) {
    }

    #[Route(
        '/{term}/{yearGroup}/{code}',
        name: 'app_tenant_report_performance_verify',
        requirements: ['term' => '\\d+', 'yearGroup' => '\\d+', 'code' => '[A-Za-z0-9]+'],
        methods: ['GET']
    )]
    public function pdf(int $term, int $yearGroup, string $code, Request $request): Response
    {
        $expected = $this->codes->for($term, $yearGroup);
        $given = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        if (!hash_equals($expected, $given)) {
            return $this->unavailable();
        }

        $termEntity = $this->em->getRepository(Term::class)->find($term);
        if (!$termEntity) {
            return $this->unavailable();
        }

        $yearGroupEntity = $yearGroup > 0
            ? $this->em->getRepository(YearGroup::class)->find($yearGroup)
            : null;

        if ($yearGroup > 0 && !$yearGroupEntity) {
            return $this->unavailable();
        }

        $data = $this->builder->build($termEntity, $yearGroupEntity?->getId());
        $school = $this->em->getRepository(School::class)->findOneBy([]);

        $verifyUrl = $this->generateUrl(
            'app_tenant_report_performance_verify',
            ['term' => $term, 'yearGroup' => $yearGroup, 'code' => $expected],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $pdf = $this->pdfRenderer->render(
            $data,
            $termEntity,
            $yearGroupEntity,
            $school,
            $verifyUrl,
            $this->codes->formatted($expected)
        );

        $termName = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $termEntity->getName()) ?: 'term';
        $filename = trim('school-performance-' . $termName, '-');
        if ($yearGroupEntity) {
            $filename .= '-' . trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $yearGroupEntity->getName()), '-');
        }

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '.pdf"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function unavailable(): Response
    {
        return $this->render('tenant/reports/performance/shared_unavailable.html.twig')
            ->setStatusCode(Response::HTTP_GONE);
    }
}
