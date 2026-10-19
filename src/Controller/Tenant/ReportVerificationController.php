<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ReportVerification;
use App\Entity\Tenant\School;
use App\Service\Results\ReportVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page a person reaches by scanning the QR on a printed report.
 *
 * It confirms that a specific pupil's report is genuine for a specific term and
 * deliberately shows **no marks** — a third party does not need the child's
 * results to trust the document.
 */
#[Route('/verify')]
final class ReportVerificationController extends AbstractController
{
    #[Route('/{code}', name: 'app_tenant_verify', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9-]+'])]
    public function show(string $code, ReportVerificationService $verifications, EntityManagerInterface $em): Response
    {
        $verification = $verifications->find($code);

        if (!$verification instanceof ReportVerification) {
            $response = $this->render('tenant/reports/verify_invalid.html.twig', ['code' => $code]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $student = $verification->getStudent();
        $term = $verification->getTerm();

        $response = $this->render('tenant/reports/verify.html.twig', [
            'verification' => $verification,
            'student' => $student,
            'term' => $term,
            'school' => $em->getRepository(School::class)->findOneBy([]),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
