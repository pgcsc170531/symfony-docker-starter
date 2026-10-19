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
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The proprietor/agency view: whole-school performance, not one pupil.
 *
 * Screen A — executive brief, Screen B — subjects, Screen C — classes.
 * See docs/results/PERFORMANCE-ANALYTICS-DESIGN.md.
 */
#[Route('/reports/performance')]
#[IsGranted('ROLE_ADMIN')]
final class SchoolPerformanceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SchoolPerformanceBuilder $builder,
        private SchoolPerformancePdfRenderer $pdfRenderer,
        private UriSigner $signer,
        private PerformanceReportCode $codes,
    ) {
    }

    #[Route('', name: 'app_tenant_report_performance', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderScreen('tenant/reports/performance/index.html.twig', $request);
    }

    #[Route('/subjects', name: 'app_tenant_report_performance_subjects', methods: ['GET'])]
    public function subjects(Request $request): Response
    {
        return $this->renderScreen('tenant/reports/performance/subjects.html.twig', $request);
    }

    #[Route('/classes', name: 'app_tenant_report_performance_classes', methods: ['GET'])]
    public function classes(Request $request): Response
    {
        return $this->renderScreen('tenant/reports/performance/classes.html.twig', $request);
    }

    #[Route('/pdf', name: 'app_tenant_report_performance_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        $context = $this->context($request);

        $termId = (int) $context['term']->getId();
        $yearGroupId = $context['yearGroup']?->getId() ?? 0;
        $code = $this->codes->for($termId, $yearGroupId);

        $verifyUrl = $this->generateUrl(
            'app_tenant_report_performance_verify',
            ['term' => $termId, 'yearGroup' => $yearGroupId, 'code' => $code],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->pdfResponse(
            $context['data'],
            $context['term'],
            $context['yearGroup'],
            $context['school'],
            !$request->query->getBoolean('download'),
            $verifyUrl,
            $this->codes->formatted($code)
        );
    }

    #[Route('/share', name: 'app_tenant_report_performance_share', methods: ['POST'])]
    public function share(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('performance_share', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('That form expired. Reload the page and try again.');
        }

        // "All year groups" is the default option and posts an empty string.
        // getInt() rejects that with a 400, so the default choice could not be
        // shared at all — cast instead, empty means "the whole school".
        $termId = (int) ($request->request->get('term') ?: $request->query->get('term'));
        $yearGroupId = (int) $request->request->get('year_group', 0);
        $term = $termId > 0 ? $this->em->getRepository(Term::class)->find($termId) : null;
        if (!$term) {
            throw $this->createNotFoundException('No such academic term.');
        }

        $expires = new \DateTimeImmutable('+30 days');
        $url = $this->generateUrl(
            'app_tenant_report_performance_shared_pdf',
            ['term' => $termId, 'yearGroup' => $yearGroupId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $request->getSession()->set('performance_share_url', $this->signer->sign($url, $expires));
        $request->getSession()->set('performance_share_expires', $expires->getTimestamp());

        return $this->redirectToRoute('app_tenant_report_performance', [
            'term' => $termId,
            'year_group' => $yearGroupId > 0 ? $yearGroupId : '',
        ]);
    }

    private function renderScreen(string $template, Request $request): Response
    {
        $context = $this->context($request);
        $context['share_url'] = $request->getSession()->remove('performance_share_url');
        $context['share_expires'] = $request->getSession()->remove('performance_share_expires');

        return $this->render($template, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        $terms = $this->em->getRepository(Term::class)->findBy([], ['startDate' => 'DESC', 'id' => 'DESC']);
        $termId = (int) $request->query->get('term', 0);
        $term = $termId > 0
            ? $this->em->getRepository(Term::class)->find($termId)
            : ($this->em->getRepository(Term::class)->findOneBy(['isActive' => true]) ?: ($terms[0] ?? null));

        if (!$term) {
            throw $this->createNotFoundException('No academic term is available.');
        }

        $yearGroupId = (int) $request->query->get('year_group', 0) ?: null;
        $yearGroup = $yearGroupId ? $this->em->getRepository(YearGroup::class)->find($yearGroupId) : null;
        if ($yearGroupId && !$yearGroup) {
            throw $this->createNotFoundException('No such year group.');
        }

        return [
            'data' => $this->builder->build($term, $yearGroupId),
            'term' => $term,
            'terms' => $terms,
            'yearGroups' => $this->em->getRepository(YearGroup::class)->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']),
            'yearGroup' => $yearGroup,
            'school' => $this->em->getRepository(School::class)->findOneBy([]),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pdfResponse(array $data, Term $term, ?YearGroup $yearGroup, ?School $school, bool $inline, ?string $verifyUrl = null, ?string $verifyCode = null): Response
    {
        $pdf = $this->pdfRenderer->render($data, $term, $yearGroup, $school, $verifyUrl, $verifyCode);

        $termName = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $term->getName()) ?: 'term';
        $filename = trim('school-performance-' . $termName, '-');
        if ($yearGroup) {
            $filename .= '-' . trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $yearGroup->getName()), '-');
        }

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '.pdf"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
