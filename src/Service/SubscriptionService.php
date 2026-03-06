<?php

namespace App\Service;

use App\Entity\Landlord\Plan;
use App\Entity\Landlord\School;
use App\Entity\Landlord\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SubscriptionService
{
    public function __construct(
        // Force this service to use the 'landlord' connection
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $em,

        // 🟢 INJECT WALLET SERVICE
        private WalletService $walletService
    ) {}

    /**
     * Creates a new Subscription for a School (First time)
     */
    public function createSubscription(School $school, Plan $plan, bool $isTrial = false): Subscription
    {
        $subscription = new Subscription();
        $subscription->setSchool($school);
        $subscription->setPlan($plan);
        
        $startDate = new \DateTime();
        $subscription->setStartDate($startDate);

        // CENTRALIZED DURATION LOGIC
        $expiresAt = $this->calculateExpiryDate($startDate, $plan, $isTrial);
        $subscription->setEndDate($expiresAt);

        // Set Status
        $status = $isTrial ? 'TRIAL' : 'ACTIVE';
        $subscription->setStatus($status);

        $this->em->persist($subscription);

        // 🟢 WALLET LOGIC: Award Credits for New Subscription
        // We do NOT give credits for Free Trials, only paid plans.
        if (!$isTrial && $plan->getFreeCreditAmount() > 0) {
            $this->walletService->addCredit(
                $school,
                (float) $plan->getFreeCreditAmount(),
                "Subscription Bonus: " . $plan->getName(),
                "SUB-NEW-" . uniqid()
            );
        }

        $this->em->flush();

        return $subscription;
    }

    /**
     * Calculates the end date based on Plan Duration.
     */
    public function calculateExpiryDate(\DateTimeInterface $startDate, Plan $plan, bool $isTrial): \DateTime
    {
        // Clone to avoid modifying the original $startDate object
        $date = clone $startDate;

        if ($isTrial) {
            return $date->modify('+14 days');
        }

        // Standard Plan Logic (e.g., 4 months)
        $months = $plan->getDurationMonths() ?? 1; 
        return $date->modify("+{$months} months");
    }

    /**
     * Handle Renewals (Same Plan)
     * Adds time to the existing End Date (Stacking)
     */
    public function renewSubscription(Subscription $subscription): void
    {
        $plan = $subscription->getPlan();
        
        // If expired, start from TODAY. If active, add to END DATE.
        $baseDate = $subscription->isValid() ? $subscription->getEndDate() : new \DateTime();
        
        // Calculate new end date
        $newEndDate = $this->calculateExpiryDate($baseDate, $plan, false);
        
        $subscription->setEndDate($newEndDate);
        $subscription->setStatus('ACTIVE');

        // 🟢 WALLET LOGIC: Award Credits for Renewal
        if ($plan->getFreeCreditAmount() > 0) {
            $this->walletService->addCredit(
                $subscription->getSchool(),
                (float) $plan->getFreeCreditAmount(),
                "Renewal Bonus: " . $plan->getName(),
                "SUB-RENEW-" . $subscription->getId() . '-' . uniqid()
            );
        }
        
        $this->em->flush();
    }

    /**
     * Handle Plan Upgrades/Switches (Different Plan)
     * Resets the cycle to start freshly from TODAY
     */
    public function upgradeSubscription(Subscription $subscription, Plan $newPlan): void
    {
        // 1. Switch the Plan entity
        $subscription->setPlan($newPlan);

        // 2. Start a FRESH cycle from TODAY
        // We reset the start date because the billing cycle effectively changes.
        $now = new \DateTime();
        $subscription->setStartDate($now);

        // 3. Calculate new End Date from TODAY
        $newEndDate = $this->calculateExpiryDate($now, $newPlan, false);
        $subscription->setEndDate($newEndDate);

        $subscription->setStatus('ACTIVE');

        // 🟢 WALLET LOGIC: Award Credits for Upgrade
        if ($newPlan->getFreeCreditAmount() > 0) {
            $this->walletService->addCredit(
                $subscription->getSchool(),
                (float) $newPlan->getFreeCreditAmount(),
                "Upgrade Bonus: " . $newPlan->getName(),
                "SUB-UPGRADE-" . $subscription->getId() . '-' . uniqid()
            );
        }
        
        $this->em->flush();
    }
}