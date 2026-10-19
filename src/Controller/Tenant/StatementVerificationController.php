<?php

namespace App\Controller\Tenant;

use App\Entity\Landlord\StatementVerification;
use App\Entity\Tenant\School;
use App\Service\Subscription\StatementVerificationService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page a person reaches by scanning the QR on a printed Value & Billing
 * statement.
 *
 * It confirms who issued the document, to which school, and when — and shows
 * **no amounts**. A bank or a board needs to know the paper is genuine, not what
 * the school pays Edus.
 */
#[Route('/verify/statement')]
final class StatementVerificationController extends AbstractController
{
    #[Route('/{code}', name: 'tenant_statement_verify', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9-]+'])]
    public function show(string $code, StatementVerificationService $verifications, ManagerRegistry $doctrine): Response
    {
        $verification = $verifications->find($code);

        if (!$verification instanceof StatementVerification) {
            $response = $this->render('tenant/reports/verify_invalid.html.twig', ['code' => $code]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $response = $this->render('tenant/subscription/verify.html.twig', [
            'verification' => $verification,
            'school' => $doctrine->getManager()->getRepository(School::class)->findOneBy([]),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
