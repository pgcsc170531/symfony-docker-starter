<?php

namespace App\Controller\Tenant;

use App\Entity\Landlord\Plan;
use App\Entity\Landlord\SubscriptionPayment;
use App\Entity\Landlord\School as LandlordSchool;
use App\Form\TenantSubscriptionPaymentType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

#[Route('/subscription')]
class SubscriptionController extends AbstractController
{
   #[Route('/', name: 'tenant_subscription_index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        
        // 1. Get the Current School (Landlord Entity)
        $currentSchool = $this->getLandlordSchool($doctrine);
        
        // 2. Get their Active Subscription (if any)
        // Assuming School has a OneToOne relation with Subscription
        $currentSubscription = $currentSchool->getSubscription();

        // 3. Get Available Plans (Paid Only)
        $plans = $landlordEm->createQueryBuilder()
            ->select('p')
            ->from(Plan::class, 'p')
            ->where('p.price > 0') 
            ->orderBy('p.price', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('tenant/subscription/index.html.twig', [
            'plans' => $plans,
            'subscription' => $currentSubscription, // 👈 PASS THIS
        ]);
    }

    // 🟢 STEP 1: GENERATE SLIP (User Clicks "Choose Plan")
    // ... existing imports ...

    // 🟢 MODIFIED: INITIATE (With Duplicate Check)
    #[Route('/initiate/{planId}', name: 'tenant_subscription_initiate', methods: ['POST'])]
    public function initiate(int $planId, ManagerRegistry $doctrine): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        $landlordSchool = $this->getLandlordSchool($doctrine);

        // 1. 🛡️ GUARDRAIL: Check for existing unfinished payments
        $existingPayment = $landlordEm->getRepository(SubscriptionPayment::class)->createQueryBuilder('p')
            ->where('p.school = :school')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('school', $landlordSchool)
            ->setParameter('statuses', ['PENDING', 'VERIFYING']) // Block if Pending or Verifying
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingPayment) {
            $this->addFlash('warning', 'You already have an active invoice. Please pay it or cancel it to create a new one.');
            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $existingPayment->getId()]);
        }

        // 2. Proceed if clear
        $plan = $landlordEm->getRepository(Plan::class)->find($planId);
        if (!$plan) throw $this->createNotFoundException('Plan not found');
        if ($plan->getPrice() <= 0) {
            $this->addFlash('error', 'Free plans do not require payment.');
            return $this->redirectToRoute('tenant_subscription_index');
        }

        $payment = new SubscriptionPayment();
        $payment->setSchool($landlordSchool);
        $payment->setPlan($plan);
        $payment->setAmount($plan->getPrice());
        $payment->setStatus('PENDING');

        $landlordEm->persist($payment);
        $landlordEm->flush();

        $this->addFlash('success', 'Invoice generated. Reference: ' . $payment->getReference());
        return $this->redirectToRoute('tenant_subscription_slip', ['id' => $payment->getId()]);
    }

    // 🟢 NEW: CANCEL PAYMENT (The Escape Hatch)
    #[Route('/cancel/{id}', name: 'tenant_subscription_cancel', methods: ['POST'])]
    public function cancel(int $id, ManagerRegistry $doctrine, Request $request): Response
    {
        // CSRF Protection (Optional but recommended)
        if (!$this->isCsrfTokenValid('cancel'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid action.');
            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        $landlordEm = $doctrine->getManager('landlord');
        $payment = $landlordEm->getRepository(SubscriptionPayment::class)->find($id);
        $currentSchool = $this->getLandlordSchool($doctrine);

        if (!$payment || $payment->getSchool()->getId() !== $currentSchool->getId()) {
            throw $this->createAccessDeniedException();
        }

        // Only allow cancelling PENDING payments (not approved ones)
        if ($payment->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'You cannot cancel a payment that is being verified or already approved.');
            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        $payment->setStatus('CANCELLED');
        $landlordEm->flush();

        $this->addFlash('success', 'Invoice cancelled. You can now select a new plan.');
        return $this->redirectToRoute('tenant_subscription_index');
    }

    // 🟢 STEP 2: VIEW SLIP & UPLOAD PROOF (User returns from Bank)
    #[Route('/slip/{id}', name: 'tenant_subscription_slip', methods: ['GET', 'POST'])]
    public function slip(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SluggerInterface $slugger,
        #[Autowire('%proofs_directory%')] string $proofsDirectory
    ): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        
        // Fetch the EXISTING payment, don't create a new one
        $payment = $landlordEm->getRepository(SubscriptionPayment::class)->find($id);

        if (!$payment) throw $this->createNotFoundException('Payment slip not found');

        // Security Check: Does this school own this slip?
        $currentSchool = $this->getLandlordSchool($doctrine);
        if ($payment->getSchool()->getId() !== $currentSchool->getId()) {
             throw $this->createAccessDeniedException('This slip belongs to another school.');
        }

        $form = $this->createForm(TenantSubscriptionPaymentType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $uploadedFile = $form->get('proofOfPayment')->getData();
            
            if ($uploadedFile) {
                $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$uploadedFile->guessExtension();

                try {
                    $uploadedFile->move($proofsDirectory, $newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Error uploading receipt.');
                    return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
                }

                // Update Data
               $payment->setProofOfPayment($newFilename); // Matches Entity logic
                $payment->setStatus('VERIFYING'); // Move to next stage

                $landlordEm->flush();

                $this->addFlash('success', 'Receipt uploaded! Admin will verify shortly.');
                return $this->redirectToRoute('tenant_subscription_index');
            }
        }
        
        return $this->render('tenant/subscription/pay.html.twig', [
            'payment' => $payment, // Pass Payment (which has Plan inside it)
            'form' => $form->createView(),
        ]);
    }

    /**
     * 🟢 FINAL ROBUST FIX: Handles Empty DB Fields by checking URL
     */
   /**
     * 🟢 HELPER: Finds Landlord School & Tells you HOW it found it.
     */
    private function getLandlordSchool(ManagerRegistry $doctrine): LandlordSchool
    {
        $landlordEm = $doctrine->getManager('landlord');
        
        /** @var \App\Entity\Tenant\User $user */
        $user = $this->getUser();
        $tenantSchool = $user->getSchool();

        if (!$tenantSchool) throw $this->createAccessDeniedException();

        // ---------------------------------------------------------
        // 🔍 STEP 1: TRY DATABASE (The "Right" Way)
        // ---------------------------------------------------------
        $subdomain = null;
        $source = 'Unknown';

        // Check if the getter exists and returns a value
        if (method_exists($tenantSchool, 'getSubdomain') && $tenantSchool->getSubdomain()) {
            $subdomain = $tenantSchool->getSubdomain();
            $source = '✅ Database Column';
        } 
        elseif (method_exists($tenantSchool, 'getSlug') && $tenantSchool->getSlug()) {
            $subdomain = $tenantSchool->getSlug();
            $source = '✅ Database Slug';
        }

        // ---------------------------------------------------------
        // 🔍 STEP 2: TRY URL FALLBACK (If Database failed)
        // ---------------------------------------------------------
        if (empty($subdomain)) {
            // Get URL: e.g., "divine-wisdom.edus.ng"
            $host = $_SERVER['HTTP_HOST'] ?? ''; 
            $parts = explode('.', $host);

            // If we have parts (e.g. [divine-wisdom, edus, ng]) take the first one
            if (count($parts) > 1) {
                $subdomain = $parts[0];
                $source = '⚠️ URL Fallback (Database was empty)';
            } else {
                // Handle Localhost (e.g. "localhost:8000")
                // You might want to hardcode a test school for development
                if (str_contains($host, 'localhost')) {
                    $subdomain = 'default'; // Change this to a real school subdomain in your DB
                    $source = '🔧 Localhost Default';
                }
            }
        }

        // ---------------------------------------------------------
        // 🔍 STEP 3: FINAL VERIFICATION
        // ---------------------------------------------------------
        if (empty($subdomain)) {
            throw $this->createNotFoundException('CRITICAL: Could not find School Subdomain in DB or URL.');
        }

        $landlordSchool = $landlordEm->getRepository(LandlordSchool::class)->findOneBy([
            'subdomain' => $subdomain
        ]);

        if (!$landlordSchool) {
            throw $this->createNotFoundException("Configuration Error: System found subdomain '{$subdomain}' via [{$source}], but no Landlord Account matches it.");
        }

        // 💡 DEBUG INFO: Show the user how we found it (Remove this line in Production)
        // $this->addFlash('info', "System connected to School: {$subdomain} (Source: {$source})");

        return $landlordSchool;
    }
}