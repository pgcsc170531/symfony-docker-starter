<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Expense;
use App\Entity\Tenant\ExpenseCategory;
use App\Form\ExpenseCategoryType;
use App\Form\ExpenseType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/expenses')]
class ExpenseController extends AbstractController
{
    #[Route('/', name: 'app_tenant_expense_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        // Handle "Add Expense" Form
        $expense = new Expense();
        $form = $this->createForm(ExpenseType::class, $expense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($expense);
            $em->flush();
            $this->addFlash('success', 'Expense recorded successfully!');
            return $this->redirectToRoute('app_tenant_expense_index');
        }

        // List Recent Expenses
        $expenses = $em->getRepository(Expense::class)->findBy([], ['expenseDate' => 'DESC', 'id' => 'DESC']);
        
        // Calculate Total
        $totalSpent = 0;
        foreach ($expenses as $e) { $totalSpent += $e->getAmount(); }

        return $this->render('tenant/expense/index.html.twig', [
            'expenses' => $expenses,
            'form' => $form,
            'totalSpent' => $totalSpent
        ]);
    }

    #[Route('/categories', name: 'app_tenant_expense_category', methods: ['GET', 'POST'])]
    public function categories(Request $request, EntityManagerInterface $em): Response
    {
        $category = new ExpenseCategory();
        $form = $this->createForm(ExpenseCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
            $em->flush();
            $this->addFlash('success', 'Category added!');
            return $this->redirectToRoute('app_tenant_expense_category');
        }

        $categories = $em->getRepository(ExpenseCategory::class)->findAll();

        return $this->render('tenant/expense/category.html.twig', [
            'categories' => $categories,
            'form' => $form
        ]);
    }
}