<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Product; // <--- Added this required import
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
    // 1. SEARCH: The entry point for the cashier
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

    // 2. CART SALE: The main POS Logic (Replaces the old newSale)
    #[Route('/cart/{studentId}', name: 'app_tenant_store_cart_sale', methods: ['GET', 'POST'])]
    public function cartSale(int $studentId, Request $request, EntityManagerInterface $em): Response
    {
        $student = $em->getRepository(Student::class)->find($studentId);
        $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        
        if (!$student) {
             throw $this->createNotFoundException('Student not found');
        }
        if (!$activeTerm) {
            $this->addFlash('error', 'No active term found.');
            return $this->redirectToRoute('app_tenant_student_index');
        }
        
        // Handle the final POST submission from the JavaScript Cart
        if ($request->isMethod('POST')) {
            $data = json_decode($request->getContent(), true);
            $cartItems = $data['items'] ?? [];

            if (empty($cartItems)) {
                return $this->json(['error' => 'No items in cart to process.'], 400);
            }
            
            // --- TRANSACTION START ---
            
            $invoice = new Invoice();
            $invoice->setStudent($student);
            $invoice->setTerm($activeTerm);
            $invoice->setSession($activeTerm->getSession());
            $invoice->setType('STORE'); 
            $invoice->setStatus('UNPAID');

            // NEW: Find Student's Class for this Session
            $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
                'student' => $student,
                'session' => $activeTerm->getSession()
            ]);
            
            if ($enrollment) {
                $invoice->setClassroom($enrollment->getClassroom()); // <--- ADD THIS LINE
            }

            $totalInvoiceAmount = 0;

            foreach ($cartItems as $cartItem) {
                $product = $em->getRepository(Product::class)->find($cartItem['productId']);
                $quantity = (int)$cartItem['quantity'];

                // Validation
                if (!$product) {
                    return $this->json(['error' => "Product ID {$cartItem['productId']} not found."], 400);
                }
                if ($product->getStockQuantity() < $quantity || $quantity <= 0) {
                     return $this->json(['error' => "Stock issue for {$product->getName()}. Only {$product->getStockQuantity()} remaining."], 400);
                }

                // A. DEDUCT STOCK
                $product->setStockQuantity($product->getStockQuantity() - $quantity);
                $em->persist($product);

                // B. CREATE INVOICE ITEM
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
            
            // C. FINALIZE INVOICE
            $invoice->setTotalAmount((string)$totalInvoiceAmount);
            $em->persist($invoice);
            
            $em->flush();
            // --- TRANSACTION END ---

            $this->addFlash('success', 'Store Invoice Generated successfully!');
            // Return JSON redirect for the frontend JS
            return $this->json(['redirect' => $this->generateUrl('app_tenant_invoice_show', ['id' => $invoice->getId()])], 200);
        }

        // GET Request: Render the POS View
        $products = $em->getRepository(Product::class)->findBy([], ['name' => 'ASC']);

        return $this->render('tenant/store/cart_sale.html.twig', [
            'student' => $student,
            'products' => $products,
        ]);
    }

    // 3. API: Helper for JavaScript to get price/stock
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