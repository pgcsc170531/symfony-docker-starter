<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Product;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Term;
// 🟢 1. IMPORT LANDLORD SCHOOL & REGISTRY
use App\Entity\Landlord\School as LandlordSchool; 
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_tenant_dashboard', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(EntityManagerInterface $em, ManagerRegistry $doctrine): Response // 🟢 2. INJECT DOCTRINE
    {
        // 🔒 SECURITY GATE: Redirect Parents Immediately
        if ($this->isGranted('ROLE_PARENT') && !$this->isGranted('ROLE_STAFF')) {
            return $this->redirectToRoute('app_parent_dashboard');
        }

        // ========================================================
        // 🟢 3. FETCH LANDLORD SCHOOL (FOR WALLET BALANCE)
        // ========================================================
        /** @var \App\Entity\Tenant\User $user */
        $user = $this->getUser();
        $subdomain = null;

        // Try 1: Get from User's linked School Entity
        if ($user && method_exists($user, 'getSchool') && $user->getSchool()) {
            $subdomain = $user->getSchool()->getSubdomain();
        }

        // Try 2: URL Fallback (If DB column is empty due to old provisioning)
        if (!$subdomain) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $parts = explode('.', $host);
            // If host is "ubmck.edus.ng", parts[0] is "ubmck"
            $subdomain = $parts[0]; 
        }

        // Fetch the Landlord Entity
        $landlordSchool = $doctrine->getManager('landlord')
            ->getRepository(LandlordSchool::class)
            ->findOneBy(['subdomain' => $subdomain]);

        // ========================================================
        // 🚀 ONBOARDING CHECK (Logic for the Welcome Modal)
        // ========================================================
        
        // 1. Check if Classes exist. If 0, the school is "Fresh".
        $classCount = $em->getRepository(Classroom::class)->count([]);

        // 2. Get School Name & User Name for the Welcome Message
        $schoolName = 'Your School'; 
        $userName = $user ? $user->getFullName() : 'User';

        if ($user) {
            // Check Tenant/School relationship
            if (method_exists($user, 'getTenant') && $user->getTenant()) {
                $schoolName = $user->getTenant()->getName();
            } 
            elseif (method_exists($user, 'getSchool') && $user->getSchool()) {
                $schoolName = $user->getSchool()->getName();
            }
            // Fallback: Check the School table just in case
            elseif ($em->getRepository(School::class)->count([]) > 0) {
                $school = $em->getRepository(School::class)->find(1);
                if ($school) $schoolName = $school->getName();
            }
        }

        // ========================================================
        // 📊 SCHOOL OPERATIONS LOGIC (Only for Staff/Admins)
        // ========================================================

        // 3. Total Students
        $studentCount = $em->getRepository(Student::class)->count([]);

        // 4. Total Outstanding Debt
        $qbTotal = $em->createQueryBuilder();
        $totalInvoiced = $qbTotal->select('SUM(i.totalAmount)')
            ->from(Invoice::class, 'i')
            ->getQuery()->getSingleScalarResult() ?? 0;

        $qbPaid = $em->createQueryBuilder();
        $totalPaid = $qbPaid->select('SUM(i.paidAmount)')
            ->from(Invoice::class, 'i')
            ->getQuery()->getSingleScalarResult() ?? 0;

        $totalDebt = $totalInvoiced - $totalPaid;

        // 5. Today's Collection (Cash In Today)
        $today = new \DateTime('today 00:00:00');
        $qbToday = $em->createQueryBuilder();
        $cashInToday = $qbToday->select('SUM(p.amount)')
            ->from(Payment::class, 'p')
            ->where('p.createdAt >= :today')
            ->setParameter('today', $today)
            ->getQuery()->getSingleScalarResult() ?? 0;

        // 6. Low Stock Alerts
        $lowStock = $em->getRepository(Product::class)->createQueryBuilder('p')
            ->where('p.stockQuantity < 5')
            ->orderBy('p.stockQuantity', 'ASC')
            ->getQuery()->getResult();
            
        // 7. Total Unpaid Invoices
        $debtors = $em->getRepository(Invoice::class)->findBy(
            ['status' => 'UNPAID'],
            ['totalAmount' => 'DESC'],
            5 
        );

        $currentSession = $em->getRepository(Session::class)->findOneBy(['isActive' => true]);
        $currentTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        return $this->render('tenant/dashboard/index.html.twig', [
            // 🟢 4. PASS LANDLORD SCHOOL TO VIEW
            'landlordSchool' => $landlordSchool, 
            
            'studentCount' => $studentCount,
            'totalDebt' => $totalDebt,
            'cashInToday' => $cashInToday,
            'lowStock' => $lowStock,
            'debtors' => $debtors,
            
            'school_name' => $schoolName,
            'user_name'   => $userName,
            'show_onboarding_modal' => ($classCount === 0),
            'currentSession' => $currentSession,
            'currentTerm' => $currentTerm,
        ]);
    }
}