<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\CreditRequest;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Route('/admin/credit-request')]
class CreditRequestController extends AbstractController
{
    #[Route('/', name: 'landlord_credit_request_index')]
    public function index(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $em
    ): Response
    {
        // Show PENDING requests at the top
        $requests = $em->getRepository(CreditRequest::class)->findBy([], ['status' => 'ASC', 'createdAt' => 'DESC']);

        return $this->render('landlord/credit_request/index.html.twig', [
            'requests' => $requests
        ]);
    }

    #[Route('/{id}/approve', name: 'landlord_credit_request_approve', methods: ['POST'])]
    public function approve(
        CreditRequest $creditRequest, 
        WalletService $walletService,
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $em
    ): Response
    {
        if ($creditRequest->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'This request is already processed.');
            return $this->redirectToRoute('landlord_credit_request_index');
        }

        // 1. FUND THE WALLET
        $walletService->addCredit(
            $creditRequest->getSchool(),
            (float) $creditRequest->getAmount(),
            "Top-up: " . $creditRequest->getReference(),
            $creditRequest->getReference()
        );

        // 2. MARK AS APPROVED
        $creditRequest->setStatus('APPROVED');
        $em->flush();

        $this->addFlash('success', 'Wallet funded successfully!');
        return $this->redirectToRoute('landlord_credit_request_index');
    }

    #[Route('/{id}/reject', name: 'landlord_credit_request_reject', methods: ['POST'])]
    public function reject(
        CreditRequest $creditRequest,
        Request $request, 
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $em
    ): Response
    {
        $reason = $request->request->get('reason', 'Payment verification failed');
        
        $creditRequest->setStatus('REJECTED');
        $creditRequest->setAdminNote($reason);
        $em->flush();

        $this->addFlash('warning', 'Request rejected.');
        return $this->redirectToRoute('landlord_credit_request_index');
    }
}