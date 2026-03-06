<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Expense;
use App\Entity\Tenant\Payment;
use App\Form\ReportDateRangeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/report')]
class ReportController extends AbstractController
{
    #[Route('/profit-loss', name: 'app_tenant_report_profit_loss', methods: ['GET', 'POST'])]
    public function profitLoss(Request $request, EntityManagerInterface $em): Response
    {
        // 1. Defaults
        $startDate = new \DateTime('first day of this month 00:00:00');
        $endDate = new \DateTime('now 23:59:59');

        // 2. Handle Form
        $form = $this->createForm(ReportDateRangeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $startDate = $data['startDate']->setTime(0, 0, 0);
            $endDate = $data['endDate']->setTime(23, 59, 59);
        }

        // 3. Query Income
        // FIX: Changed 'p.paidAt' to 'p.createdAt' and added Status Check
        $qbIncome = $em->createQueryBuilder();
        $qbIncome->select('i.type as income_type, SUM(p.amount) as total')
            ->from(Payment::class, 'p')
            ->join('p.invoice', 'i')
            ->where('p.createdAt BETWEEN :start AND :end') // 👈 FIXED: Uses Database Column
            ->andWhere("p.status = 'CONFIRMED'")           // 👈 SAFETY: Only confirmed money
            ->groupBy('i.type')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);
        
        $incomeResults = $qbIncome->getQuery()->getResult();

        // Organize Income Data
        $incomeFees = 0.0;
        $incomeStore = 0.0;

        foreach ($incomeResults as $row) {
            if ($row['income_type'] === 'ACADEMIC') {
                $incomeFees = (float) $row['total'];
            } else {
                $incomeStore += (float) $row['total'];
            }
        }
        $totalIncome = $incomeFees + $incomeStore;

        // 4. Query Expenses
        $qbExpense = $em->createQueryBuilder();
        $qbExpense->select('SUM(e.amount) as total')
            ->from(Expense::class, 'e')
            ->where('e.expenseDate BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate);

        $totalExpense = (float) ($qbExpense->getQuery()->getSingleScalarResult() ?? 0);

        // 5. Calculate Profit
        $netProfit = $totalIncome - $totalExpense;
        
        return $this->render('tenant/report/profit_loss.html.twig', [
            'form' => $form->createView(), // 👈 FIX: Must call createView()
            'startDate' => $startDate,
            'endDate' => $endDate,
            'incomeFees' => (float)$incomeFees,    // 👈 RECOMMENDATION: Cast to float for safety
            'incomeStore' => (float)$incomeStore,
            'totalIncome' => (float)$totalIncome,
            'totalExpense' => (float)$totalExpense,
            'netProfit' => (float)$netProfit
        ]);
    }
}