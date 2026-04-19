<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Product; 
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Form\StudentSearchType;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Tenant\Enrollment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/store/sales')]
class StoreSalesController extends AbstractController
{
    // ==========================================
    // 1. SEARCH PAGE
    // ==========================================
    #[Route('/search', name: 'app_tenant_store_search', methods: ['GET', 'POST'])]
    public function search(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(StudentSearchType::class);
        $form->handleRequest($request);

        $students = [];
        $query = '';

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $query = $data['query'];

            if (!empty($query)) {
                $students = $em->getRepository(Student::class)
                    ->createQueryBuilder('s')
                    ->where('s.firstName LIKE :query OR s.lastName LIKE :query OR s.admissionNumber LIKE :query')
                    ->setParameter('query', '%' . $query . '%')
                    ->setMaxResults(50)
                    ->getQuery()
                    ->getResult();
            }
        }

        return $this->render('tenant/store/search.html.twig', [
            'form' => $form,
            'students' => $students,
            'query' => $query
        ]);
    }

    // ==========================================
    // 2. RENDER POS TERMINAL (GET ONLY)
    // ==========================================
    #[Route('/cart/{studentId?}', name: 'app_tenant_store_cart_sale', methods: ['GET'])]
    public function cartSale(?int $studentId, EntityManagerInterface $em): Response
    {
        $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        
        if (!$activeTerm) {
            $this->addFlash('error', 'No active term found.');
            return $this->redirectToRoute('app_tenant_store_search');
        }

        $student = null;
        $products = [];

        if ($studentId) {
            $student = $em->getRepository(Student::class)->find($studentId);
            if (!$student) throw $this->createNotFoundException('Student not found');
            
            $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
                'student' => $student,
                'session' => $activeTerm->getSession()
            ]);

            if ($enrollment && $enrollment->getClassroom()) {
                $singularClassName = $enrollment->getClassroom()->getName();
                $baseLevel = trim(preg_replace('/[a-zA-Z]$/', '', $singularClassName));

                $products = $em->getRepository(Product::class)->createQueryBuilder('p')
                    ->leftJoin('p.classroom', 'c')
                    ->where('p.classroom IS NULL') 
                    ->orWhere('c.name LIKE :baseLevel') 
                    ->setParameter('baseLevel', $baseLevel . '%')
                    ->orderBy('p.category', 'ASC')
                    ->addOrderBy('p.name', 'ASC')
                    ->getQuery()
                    ->getResult();
            }
        } 
        
        if (empty($products)) {
            $products = $em->getRepository(Product::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']);
        }

        return $this->render('tenant/store/cart_sale.html.twig', [
            'student' => $student,
            'products' => $products,
        ]);
    }

    // ==========================================
    // 3. CHECKOUT: STUDENT (POST ONLY)
    // ==========================================
    #[Route('/checkout/student/{id}', name: 'app_tenant_store_checkout_student', methods: ['POST'])]
    public function checkoutStudent(Student $student, Request $request, EntityManagerInterface $em): Response
    {
        $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        $data = json_decode($request->getContent(), true);
        $cartItems = $data['items'] ?? [];

        if (empty($cartItems)) return $this->json(['error' => 'Cart is empty.'], 400);

        $invoice = new Invoice();
        $invoice->setTerm($activeTerm);
        $invoice->setSession($activeTerm->getSession());
        $invoice->setType('STORE');
        $invoice->setStudent($student);
        $invoice->setStatus('UNPAID');
        
        $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
            'student' => $student,
            'session' => $activeTerm->getSession()
        ]);
        
        if ($enrollment) {
            $invoice->setClassroom($enrollment->getClassroom()); 
        }

        try {
            $totalAmount = $this->processCartAndDeductStock($cartItems, $invoice, $em);
            $invoice->setTotalAmount((string)$totalAmount);
            
            $em->persist($invoice);
            $em->flush();

            $this->addFlash('success', 'Store Invoice Generated successfully!');
            return $this->json(['redirect' => $this->generateUrl('app_tenant_invoice_show', ['id' => $invoice->getId()])], 200);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    // ==========================================
    // 4. CHECKOUT: WALK-IN (POST ONLY)
    // ==========================================
    #[Route('/checkout/walkin', name: 'app_tenant_store_checkout_walkin', methods: ['POST'])]
    public function checkoutWalkIn(Request $request, EntityManagerInterface $em): Response
    {
        $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        $data = json_decode($request->getContent(), true);
        $cartItems = $data['items'] ?? [];
        $buyerName = $data['buyerName'] ?? null;

        if (empty($cartItems)) return $this->json(['error' => 'Cart is empty.'], 400);
        if (empty($buyerName)) return $this->json(['error' => 'Walk-in customer name is required.'], 400);

        $invoice = new Invoice();
        $invoice->setTerm($activeTerm);
        $invoice->setSession($activeTerm->getSession());
        $invoice->setType('STORE');
        $invoice->setBuyerName($buyerName);
        $invoice->setStatus('PAID');

        try {
            $totalAmount = $this->processCartAndDeductStock($cartItems, $invoice, $em);
            $invoice->setTotalAmount((string)$totalAmount);
            $invoice->setPaidAmount((string)$totalAmount);
            
            $em->persist($invoice); 

            $payment = new Payment();
            $payment->setInvoice($invoice);
            $payment->setAmount((string)$totalAmount);
            $payment->setMethod('CASH');
            $payment->setReferenceCode(date('ym') . rand(1000, 9999));
            $payment->setStatus('CONFIRMED');
            $payment->setConfirmedAt(new \DateTimeImmutable());
            $payment->setConfirmedBy($this->getUser()->getUserIdentifier());
            
            $em->persist($payment);
            $em->flush();

            $this->addFlash('success', 'Walk-in Cash Sale completed successfully!');
            return $this->json(['redirect' => $this->generateUrl('app_tenant_payment_receipt_walkin', ['id' => $payment->getId()])], 200);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    // ==========================================
    // 5. SHARED HELPER: CART MATH & INVENTORY
    // ==========================================
    private function processCartAndDeductStock(array $cartItems, Invoice $invoice, EntityManagerInterface $em): float
    {
        $totalInvoiceAmount = 0;

        foreach ($cartItems as $cartItem) {
            $product = $em->getRepository(Product::class)->find($cartItem['productId']);
            $quantity = (int)$cartItem['quantity'];

            if (!$product) {
                throw new \Exception("Product ID {$cartItem['productId']} not found.");
            }
            if ($product->getStockQuantity() < $quantity || $quantity <= 0) {
                 throw new \Exception("Stock issue for {$product->getName()}. Only {$product->getStockQuantity()} remaining.");
            }

            // Deduct Stock
            $product->setStockQuantity($product->getStockQuantity() - $quantity);
            $em->persist($product);

            // Create Invoice Item
            $unitPrice = (float)$product->getUnitPrice();
            $totalPrice = $unitPrice * $quantity;
            $totalInvoiceAmount += $totalPrice;

            $item = new InvoiceItem();
            $item->setInvoice($invoice);
            $item->setProduct($product);
            $item->setQuantity($quantity);
            $item->setAmount((string)$totalPrice);
            $em->persist($item);
        }

        return $totalInvoiceAmount;
    }

    // ==========================================
    // 6. API: PRODUCT DETAILS
    // ==========================================
    #[Route('/api/product/{id}', name: 'app_tenant_api_product_details', methods: ['GET'])]
    public function getProductDetails(int $id, EntityManagerInterface $em): Response
    {
        $product = $em->getRepository(Product::class)->find($id);

        if (!$product) {
            return $this->json(['error' => 'Product not found'], 404);
        }

        return $this->json([
            'id' => $product->getId(),
            'name' => $product->getName(),
            'price' => $product->getUnitPrice(),
            'stock' => $product->getStockQuantity(),
            'class' => $product->getClassroom() ? $product->getClassroom()->getName() : 'General',
        ]);
    }
}