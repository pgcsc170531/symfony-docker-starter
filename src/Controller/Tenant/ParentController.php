<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\SchoolEvent;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\Payment; // 👈 Needed for payment history
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/parent')]
#[IsGranted('ROLE_PARENT')]
class ParentController extends AbstractController
{
    #[Route('/dashboard', name: 'app_parent_dashboard')]
    public function dashboard(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $guardian = $em->getRepository(Guardian::class)->findOneBy(['user' => $user]);

        if (!$guardian) {
            return $this->render('tenant/parent/dashboard.html.twig', ['studentData' => [], 'flashEvents' => []]);
        }

        $students = $guardian->getStudents();
        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        // 🟢 NEW: FETCH FLASH NOTICES (URGENT ALERTS)
        // We only fetch events where isFlashNotice is true and are happening today or later
        $flashEvents = $em->getRepository(SchoolEvent::class)->createQueryBuilder('e')
            ->where('e.isFlashNotice = :flash')
            ->andWhere('e.startDate >= :today')
            ->setParameter('flash', true)
            ->setParameter('today', new \DateTime('today')) // 'today' ensures it shows events happening later today
            ->orderBy('e.startDate', 'ASC')
            ->setMaxResults(3) // Only show the 3 most urgent items
            ->getQuery()
            ->getResult();

        // PREPARE DATA
        $studentData = [];
        foreach ($students as $student) {
            $invoice = $em->getRepository(Invoice::class)->findOneBy([
                'student' => $student,
                'term' => $term,
            ]);

            $lastPayment = null;
            if ($invoice) {
                $lastPayment = $em->getRepository(Payment::class)->findOneBy(
                    ['invoice' => $invoice],
                    ['createdAt' => 'DESC']
                );
            }

            $studentData[] = [
                'student' => $student,
                'invoice' => $invoice,
                'lastPayment' => $lastPayment
            ];
        }

        return $this->render('tenant/parent/dashboard.html.twig', [
            'studentData' => $studentData,
            'term' => $term,
            'flashEvents' => $flashEvents // 👈 Passed to the view
        ]);
    }
    
    // ... (Your existing calendar method is perfect, keep it as is!) ...
    #[Route('/calendar/{year}/{month}', name: 'app_parent_calendar', requirements: ['year' => '\d+', 'month' => '\d+'])]
    public function calendar(EntityManagerInterface $em, int $year = null, int $month = null): Response
    {
        // ... (Keep existing code) ...
        $today = new \DateTime();
        $year = $year ?? (int)$today->format('Y');
        $month = $month ?? (int)$today->format('m');

        $startOfMonth = new \DateTimeImmutable("$year-$month-01 00:00:00");
        $endOfMonth = $startOfMonth->modify('last day of this month')->setTime(23, 59, 59);

        $events = $em->getRepository(SchoolEvent::class)->createQueryBuilder('e')
            ->where('e.startDate >= :start')
            ->andWhere('e.startDate <= :end')
            ->setParameter('start', $startOfMonth)
            ->setParameter('end', $endOfMonth)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('tenant/parent/calendar.html.twig', [
            'currentMonth' => $startOfMonth,
            'events' => $events, 
            'today' => $today,
        ]);
    }

    #[Route('/student/{id}/finance', name: 'app_parent_finance_view')]
    public function finance(Student $student, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $guardian = $em->getRepository(Guardian::class)->findOneBy(['user' => $user]);

        // 1. SECURITY: Ensure the parent actually owns this student
        if (!$guardian || !$guardian->getStudents()->contains($student)) {
            throw $this->createAccessDeniedException('You are not authorized to view this student.');
        }

        // 2. Fetch Invoices (Newest first)
        $invoices = $em->getRepository(Invoice::class)->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC']
        );

        // 3. Fetch Payments (Newest first)
        // We can find payments by joining through invoices, or if you have a direct link
        // For simplicity, let's just grab payments linked to these invoices
        $payments = [];
        foreach ($invoices as $inv) {
            foreach ($inv->getPayments() as $p) {
                $payments[] = $p;
            }
        }
        
        // Sort payments by date (descending)
        usort($payments, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $this->render('tenant/parent/finance_view.html.twig', [
            'student' => $student,
            'invoices' => $invoices,
            'payments' => $payments
        ]);
    }
}