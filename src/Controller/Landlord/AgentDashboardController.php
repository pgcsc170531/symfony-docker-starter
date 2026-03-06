<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\Agent;
use App\Entity\Landlord\SubscriptionPayment;       // Assuming these are in Landlord namespace
use App\Entity\Landlord\SupportTicket; // Assuming these are in Landlord namespace
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/agent')]
#[IsGranted('ROLE_AGENT')]
class AgentDashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_agent_dashboard')]
    public function index(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $entityManager
    ): Response
    {
        /** @var Agent $agent */
        $agent = $this->getUser();

        // 1. Fetch Agent's Schools (Using the relationship directly)
        $schools = $agent->getSchools();

        // 2. Fetch Pending Payments (Direct QueryBuilder logic)
        $pendingPayments = $entityManager->getRepository(SubscriptionPayment::class)->createQueryBuilder('p')
            ->join('p.school', 's')
            ->where('s.agent = :agent')
            ->andWhere('p.status = :status')
            ->setParameter('agent', $agent)
            ->setParameter('status', 'pending')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // 3. Fetch Open Support Tickets (Direct QueryBuilder logic)
        $openTickets = $entityManager->getRepository(SupportTicket::class)->createQueryBuilder('t')
            ->join('t.school', 's')
            ->where('s.agent = :agent')
            ->andWhere('t.status = :status')
            ->setParameter('agent', $agent)
            ->setParameter('status', 'open')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('landlord/agent/dashboard/index.html.twig', [
            'schools' => $schools,
            'pending_payments' => $pendingPayments,
            'open_tickets' => $openTickets,
        ]);
    }
}