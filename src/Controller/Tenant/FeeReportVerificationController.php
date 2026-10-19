<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\School;
use App\Service\Fees\FeeReportVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page reached by scanning the QR on a Term Fee Summary PDF.
 *
 * It confirms who issued the summary, for which term and when — and shows
 * **no amounts**. `/verify/` is already PUBLIC_ACCESS in security.yaml; this
 * route is host-scoped so each school's code resolves in its own database.
 */
#[Route('/verify/fee-report', host: '{subdomain}.%app.base_domain%')]
final class FeeReportVerificationController extends AbstractController
{
    #[Route('/{code}', name: 'tenant_fee_report_verify', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9-]+'])]
    public function show(string $code, FeeReportVerificationService $verifications, EntityManagerInterface $em): Response
    {
        $verification = $verifications->find($code);

        if ($verification === null) {
            $response = $this->render('tenant/report/fees/verify_invalid.html.twig', ['code' => $code]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $response = $this->render('tenant/report/fees/verify.html.twig', [
            'verification' => $verification,
            'school'       => $em->getRepository(School::class)->findOneBy([]),
            'document'     => $verification->getKind() === 'fee-statement'
                ? 'Statement of account'
                : 'Term fee summary',
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
