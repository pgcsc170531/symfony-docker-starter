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
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public link behind “Share with an agency”.
 *
 * The URL is signed and expires after 30 days. It serves the aggregate PDF only —
 * no pupil-level marks, no admin screens, and no database session. The token is the
 * credential, exactly like /r/{token} for a report card.
 */
#[Route('/p/performance')]
final class SharedSchoolPerformanceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SchoolPerformanceBuilder $builder,
        private SchoolPerformancePdfRenderer $pdfRenderer,
        private UriSigner $signer,
        private PerformanceReportCode $codes,
    ) {
    }

    #[Route(
        '/{term}/{yearGroup}/pdf',
        name: 'app_tenant_report_performance_shared_pdf',
        requirements: ['term' => '\\d+', 'yearGroup' => '\\d+'],
        methods: ['GET']
    )]
    public function pdf(int $term, int $yearGroup, Request $request): Response
    {
        try {
            $this->signer->verify($request);
        } catch (SignedUriException) {
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

        $code = $this->codes->for($term, $yearGroup);
        $verifyUrl = $this->generateUrl(
            'app_tenant_report_performance_verify',
            ['term' => $term, 'yearGroup' => $yearGroup, 'code' => $code],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $pdf = $this->pdfRenderer->render(
            $data,
            $termEntity,
            $yearGroupEntity,
            $school,
            $verifyUrl,
            $this->codes->formatted($code)
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
