<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ReportShareToken;
use App\Entity\Tenant\ReportArchive;
use App\Service\Results\ReportAccessGate;
use App\Service\Results\ReportArchiveService;
use App\Service\Results\ReportPdfRendererInterface;
use App\Service\Results\ReportShareService;
use App\Service\Results\ResultSheetBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public face of a share link — no login, on the school's own subdomain.
 *
 * A grandmother should be able to open this on her phone. The token is the
 * capability: it is unguessable, expires after 30 days, can be revoked by the
 * parent, and grants exactly one pupil's report and nothing else.
 * See docs/results/RESULT-CARD-DESIGN.md §6.
 */
#[Route('/r')]
final class SharedReportController extends AbstractController
{
    private const TOKEN_PATTERN = '[a-f0-9]{64}';

    public function __construct(
        private EntityManagerInterface $em,
        private ResultSheetBuilder $sheetBuilder,
        private ReportShareService $shares,
        private ReportPdfRendererInterface $pdfRenderer,
        private ReportAccessGate $gate,
        private ReportArchiveService $archives,
    ) {
    }

    #[Route('/{token}', name: 'app_tenant_report_shared', methods: ['GET'], requirements: ['token' => self::TOKEN_PATTERN])]
    public function show(string $token): Response
    {
        $share = $this->resolve($token);
        if (!$share) {
            return $this->unavailable();
        }

        $student = $share->getStudent();
        if ($student && $this->gate->isWithheld($student)) {
            return $this->withheld($share);
        }

        $sheet = $this->sheetFor($share);
        if ($sheet === null) {
            return $this->unavailable();
        }

        $share->recordView();
        $this->em->flush();

        $response = $this->render('tenant/reports/shared.html.twig', [
            'sheet' => $sheet,
            'term' => $share->getTerm(),
            'token' => $token,
            'expires_at' => $share->getExpiresAt(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{token}/pdf', name: 'app_tenant_report_shared_pdf', methods: ['GET'], requirements: ['token' => self::TOKEN_PATTERN])]
    public function pdf(string $token, Request $request): Response
    {
        $share = $this->resolve($token);
        if (!$share) {
            return $this->unavailable();
        }

        $student = $share->getStudent();
        if ($student && $this->gate->isWithheld($student)) {
            return $this->withheld($share);
        }

        $sheet = $this->sheetFor($share);
        if ($sheet === null) {
            return $this->unavailable();
        }

        $share->recordView();
        $this->em->flush();

        $response = new Response($this->pdfRenderer->render($sheet));
        if ($share->getStudent() && $share->getTerm()) {
            $this->archives->record($share->getStudent(), $share->getTerm(), ReportArchive::KIND_CARD, $response->getContent(), 'share-link');
        }
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            ($request->query->getBoolean('download') ? 'attachment' : 'inline') . '; filename="report.pdf"'
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function resolve(string $token): ?ReportShareToken
    {
        $share = $this->shares->resolve($token);
        if (!$share instanceof ReportShareToken || !$share->isUsable()) {
            return null;
        }

        return $share;
    }

    /** @return array<string, mixed>|null */
    private function sheetFor(ReportShareToken $share): ?array
    {
        $student = $share->getStudent();
        $term = $share->getTerm();
        if (!$student || !$term) {
            return null;
        }

        $sheet = $this->sheetBuilder->build($student, $term);

        return ($sheet['empty'] ?? false) ? null : $sheet;
    }

    private function unavailable(): Response
    {
        return $this->render('tenant/reports/shared_unavailable.html.twig', [], new Response('', Response::HTTP_GONE));
    }

    private function withheld(ReportShareToken $share): Response
    {
        $student = $share->getStudent();

        $response = $this->render('tenant/reports/withheld.html.twig', [
            'student' => $student,
            'term' => $share->getTerm(),
            'school' => $this->gate->school(),
            'outstanding' => $student ? $this->gate->outstanding($student) : null,
        ]);
        $response->setStatusCode(Response::HTTP_PAYMENT_REQUIRED);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
