<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\SubscriptionPayment;
use App\Entity\Landlord\Plan; // 🟢 ADD THIS
use App\Entity\Landlord\School; // 🟢 ADD THIS
use App\Entity\Landlord\Subscription; // 🟢 ADD THIS
use App\Service\SubscriptionService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request; // 🟢 ADD THIS
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/subscription')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminSubscriptionController extends AbstractController
{
    // 1. DASHBOARD: LIST PENDING PAYMENTS
    #[Route('/pending', name: 'landlord_subscription_pending')]
    public function pending(ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        
        // Fetch only payments waiting for approval
        $payments = $em->getRepository(SubscriptionPayment::class)->findBy(
            ['status' => 'VERIFYING'], 
            ['createdAt' => 'ASC']
        );

        return $this->render('landlord/subscription/pending.html.twig', [
            'payments' => $payments
        ]);
    }

    // 2. ACTION: APPROVE & TRIGGER SERVICE
    #[Route('/verify/{id}', name: 'landlord_subscription_verify', methods: ['POST'])]
    public function verify(
        int $id, 
        ManagerRegistry $doctrine,
        SubscriptionService $subscriptionService // 🟢 INJECT YOUR SERVICE
    ): Response
    {
        $em = $doctrine->getManager('landlord');
        $payment = $em->getRepository(SubscriptionPayment::class)->find($id);

        if (!$payment) throw $this->createNotFoundException();

        // A. Mark Payment as Approved
        $payment->setStatus('APPROVED');
        $payment->setVerifiedAt(new \DateTime());

        // B. Activate/Renew Subscription using the Service
        $school = $payment->getSchool();
        $newPlan = $payment->getPlan(); // The plan they just paid for
        $currentSubscription = $school->getSubscription();

        if (!$currentSubscription) {
            // SCENARIO 1: Brand new subscription (First time ever)
            $subscriptionService->createSubscription($school, $newPlan, false);
        } else {
            // SCENARIO 2: Check if Plan Changed
            $oldPlanId = $currentSubscription->getPlan()->getId();
            $newPlanId = $newPlan->getId();

            if ($oldPlanId === $newPlanId) {
                // 🟢 SAME PLAN = RENEWAL
                // Logic: Adds time to the existing End Date (Stacking)
                $subscriptionService->renewSubscription($currentSubscription);
            } else {
                // 🚀 DIFFERENT PLAN = UPGRADE/SWITCH
                // Logic: Resets the cycle to start FRESH from today
                $subscriptionService->upgradeSubscription($currentSubscription, $newPlan);
            }
        }

        $em->flush(); // Save payment status change and subscription updates

        $this->addFlash('success', 'Payment verified! Subscription has been updated successfully.');

        return $this->redirectToRoute('landlord_subscription_pending');
    }

    // 3. ACTION: REJECT
    #[Route('/reject/{id}', name: 'landlord_subscription_reject', methods: ['POST'])]
    public function reject(int $id, ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        $payment = $em->getRepository(SubscriptionPayment::class)->find($id);

        if (!$payment) throw $this->createNotFoundException();

        $payment->setStatus('DECLINED');
        $em->flush();

        $this->addFlash('error', 'Payment declined.');

        return $this->redirectToRoute('landlord_subscription_pending');
    }



    // 🟢 4. MONITOR: ACTIVE SUBSCRIPTIONS
    #[Route('/active', name: 'landlord_subscription_active')]
    public function active(ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        // Fetch all subscriptions to monitor expiry dates
        $subscriptions = $em->getRepository(Subscription::class)->findAll();

        return $this->render('landlord/subscription/active.html.twig', [
            'subscriptions' => $subscriptions
        ]);
    }

    // 🟢 5. ACTION: GENERATE SLIP ON BEHALF
    #[Route('/generate-slip/{schoolId}', name: 'landlord_subscription_generate_slip', methods: ['GET', 'POST'])]
    public function generateSlip(int $schoolId, Request $request, ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        $school = $em->getRepository(School::class)->find($schoolId);
        
        if (!$school) throw $this->createNotFoundException('School not found');

        // Handle Form Submission
        if ($request->isMethod('POST')) {
            $planId = $request->request->get('plan_id');
            $plan = $em->getRepository(Plan::class)->find($planId);
            
            // Double-check on server side
            if ($plan && $plan->getPrice() > 0) {
                $payment = new SubscriptionPayment();
                $payment->setSchool($school);
                $payment->setPlan($plan);
                $payment->setAmount($plan->getPrice());
                $payment->setStatus('PENDING');

                $em->persist($payment);
                $em->flush();

                $this->addFlash('success', 'Invoice generated! Reference: ' . $payment->getReference());
                return $this->redirectToRoute('landlord_subscription_active');
            }
        }

        // 🟢 FIX: Only fetch Paid Plans
        $plans = $em->createQueryBuilder()
            ->select('p')
            ->from(Plan::class, 'p')
            ->where('p.price > 0') // 👈 Removes Free Trial
            ->orderBy('p.price', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('landlord/subscription/generate_slip.html.twig', [
            'school' => $school,
            'plans' => $plans
        ]);
    }

    // 🟢 6. ACTION: SEND REMINDER (Stub)
    #[Route('/remind/{id}', name: 'landlord_subscription_remind')]
    public function remind(int $id, ManagerRegistry $doctrine): Response
    {
        // Future: NotificationService->sendExpiryReminder($subscription);
        $this->addFlash('info', 'Reminder queued for sending (Simulation).');
        return $this->redirectToRoute('landlord_subscription_active');
    }

}