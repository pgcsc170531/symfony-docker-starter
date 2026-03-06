<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\School;
use App\Entity\Landlord\Subscription;
use App\Entity\Landlord\SubscriptionPayment;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_landlord_dashboard')]
    public function index(ManagerRegistry $registry): Response
    {
        $em = $registry->getManager('landlord');

        // 1. Total Schools
        $schoolsCount = $em->getRepository(School::class)->count([]);

        // 2. Active Subscriptions
        $activeSubsCount = $em->createQueryBuilder()
            ->select('count(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.endDate > :now')
            ->setParameter('status', 'ACTIVE')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getSingleScalarResult();

        // 🟢 3. EXPIRING SOON (New Metric)
        // Helps you know how many schools need a "Poke" (Expiring in 14 days)
        $expiringCount = $em->createQueryBuilder()
            ->select('count(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.endDate BETWEEN :now AND :soon')
            ->setParameter('now', new \DateTime())
            ->setParameter('soon', (new \DateTime())->modify('+14 days'))
            ->getQuery()
            ->getSingleScalarResult();

        // 🟢 4. PENDING REVENUE (Fixed Logic)
        // We now sum 'VERIFYING', not 'PENDING'.
        // PENDING = They generated a slip but haven't paid.
        // VERIFYING = They uploaded a receipt. This is the money waiting for you.
        $pendingRevenue = $em->createQueryBuilder()
            ->select('SUM(p.amount)')
            ->from(SubscriptionPayment::class, 'p')
            ->where('p.status = :status')
            ->setParameter('status', 'VERIFYING') // 👈 Key Change
            ->getQuery()
            ->getSingleScalarResult();

        // 5. Wallet Liability
        $totalWalletBalance = $em->createQueryBuilder()
            ->select('SUM(s.walletBalance)')
            ->from(School::class, 's')
            ->getQuery()
            ->getSingleScalarResult();

        // 🟢 6. ACTION ITEMS (The "Verify Payments" Widget)
        // Only fetch payments that actually need your attention (Receipt Uploaded)
        $verificationQueue = $em->getRepository(SubscriptionPayment::class)->findBy(
            ['status' => 'VERIFYING'], // 👈 Key Change
            ['createdAt' => 'ASC'], // Oldest first (FIFO)
            5
        );

        // 🟢 7. RECENT ACTIVITY (Recently Approved)
        // Shows "Divine Wisdom paid ₦50,000" in the activity feed
        $recentActivity = $em->getRepository(SubscriptionPayment::class)->findBy(
            ['status' => 'APPROVED'],
            ['verifiedAt' => 'DESC'],
            5
        );

        return $this->render('landlord/dashboard/index.html.twig', [
            'schools_count' => $schoolsCount,
            'active_subs_count' => $activeSubsCount,
            'expiring_count' => $expiringCount, // Pass this to view
            'pending_revenue' => $pendingRevenue ?? 0,
            'total_wallet_balance' => $totalWalletBalance ?? 0,
            'verification_queue' => $verificationQueue,
            'recent_activity' => $recentActivity,
        ]);
    }
}