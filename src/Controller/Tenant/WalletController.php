<?php

namespace App\Controller\Tenant;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Landlord\CreditRequest; // 🟢 Make sure you created this Entity!
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

#[Route('/wallet')]
class WalletController extends AbstractController
{
    #[Route('/', name: 'tenant_wallet_index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        $school = $this->getLandlordSchool($doctrine);

        // Fetch Transactions (Sorted Newest First)
        $transactions = $school->getWalletTransactions();

        // 🟢 Fetch Pending Requests too (so they can see "Pending")
        $pendingRequests = $doctrine->getManager('landlord')
            ->getRepository(CreditRequest::class)
            ->findBy(['school' => $school, 'status' => 'PENDING']);

        return $this->render('tenant/wallet/index.html.twig', [
            'school' => $school,
            'transactions' => $transactions,
            'pendingRequests' => $pendingRequests
        ]);
    }

    // =========================================================
    // 🟢 NEW: STEP 1 - INITIATE TOP UP
    // =========================================================
    #[Route('/top-up', name: 'tenant_wallet_top_up', methods: ['POST'])]
    public function topUp(Request $request, ManagerRegistry $doctrine): Response
    {
        $amount = (float) $request->request->get('amount');

        if ($amount < 1000) {
            $this->addFlash('error', 'Minimum top-up amount is ₦1,000');
            return $this->redirectToRoute('tenant_wallet_index');
        }

        $school = $this->getLandlordSchool($doctrine);
        $landlordEm = $doctrine->getManager('landlord');

        // Create the Request
        $creditRequest = new CreditRequest();
        $creditRequest->setSchool($school);
        $creditRequest->setAmount((string)$amount);
        $creditRequest->setStatus('PENDING');
        
        $landlordEm->persist($creditRequest);
        $landlordEm->flush();

        // Redirect to Step 2 (Upload Proof)
        return $this->redirectToRoute('tenant_wallet_payment', ['id' => $creditRequest->getId()]);
    }

    // =========================================================
    // 🟢 NEW: STEP 2 - UPLOAD PROOF
    // =========================================================
    #[Route('/payment/{id}', name: 'tenant_wallet_payment', methods: ['GET', 'POST'])]
    public function payment(
        int $id, 
        Request $request, 
        ManagerRegistry $doctrine,
        SluggerInterface $slugger
    ): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        $creditRequest = $landlordEm->getRepository(CreditRequest::class)->find($id);

        // Security: Ensure request exists and belongs to THIS school
        $currentSchool = $this->getLandlordSchool($doctrine);
        
        if (!$creditRequest || $creditRequest->getSchool() !== $currentSchool) {
            throw $this->createAccessDeniedException('This request does not belong to you.');
        }

        if ($creditRequest->getStatus() !== 'PENDING') {
            $this->addFlash('warning', 'This request has already been processed.');
            return $this->redirectToRoute('tenant_wallet_index');
        }

        // HANDLE FILE UPLOAD
        if ($request->isMethod('POST')) {
            $file = $request->files->get('proof');
            
            if ($file) {
                $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

                try {
                    // 🟢 CHANGE THIS LINE TO USE THE NEW DIRECTORY
                    $file->move(
                        $this->getParameter('wallet_proofs_directory'), // 👈 UPDATED HERE
                        $newFilename
                    );
                    
                    $creditRequest->setProofFilename($newFilename);
                    $landlordEm->flush();

                    $this->addFlash('success', 'Payment proof uploaded! Admin will review shortly.');
                    return $this->redirectToRoute('tenant_wallet_index');
                } catch (FileException $e) {
                    $this->addFlash('error', 'Upload failed: ' . $e->getMessage());
                }
            } else {
                $this->addFlash('error', 'Please select a file.');
            }
        }

        return $this->render('tenant/wallet/payment.html.twig', [
            'creditRequest' => $creditRequest
        ]);
    }
    // =========================================================
    // 🟢 HELPER: CENTRALIZED SCHOOL FETCHING
    // =========================================================
    private function getLandlordSchool(ManagerRegistry $doctrine): LandlordSchool
    {
        $landlordEm = $doctrine->getManager('landlord');
        $user = $this->getUser();
        
        $tenantSchool = $user->getSchool();
        $subdomain = method_exists($tenantSchool, 'getSubdomain') ? $tenantSchool->getSubdomain() : null;

        if (!$subdomain) {
             $host = $_SERVER['HTTP_HOST'];
             $parts = explode('.', $host);
             $subdomain = $parts[0];
        }

        $school = $landlordEm->getRepository(LandlordSchool::class)->findOneBy(['subdomain' => $subdomain]);

        if (!$school) {
            throw $this->createNotFoundException('Wallet account not found.');
        }

        return $school;
    }
}