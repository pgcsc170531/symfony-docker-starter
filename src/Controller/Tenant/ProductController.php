<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Product;
use App\Entity\Tenant\Classroom;
use App\Form\ProductType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/store/products')]
class ProductController extends AbstractController
{
    // 1. INDEX & CREATE NEW ITEM
    #[Route('/', name: 'app_tenant_product_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($product);
            $em->flush();
            $this->addFlash('success', 'Item added to store!');
            return $this->redirectToRoute('app_tenant_product_index');
        }

        $products = $em->getRepository(Product::class)->findBy([], ['category' => 'ASC', 'name' => 'ASC']);

        return $this->render('tenant/product/index.html.twig', [
            'products' => $products,
            'form' => $form,
        ]);
    }

    // 2. EDIT ITEM
    #[Route('/{id}/edit', name: 'app_tenant_product_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Product updated successfully!');
            return $this->redirectToRoute('app_tenant_product_index');
        }

        return $this->render('tenant/product/edit.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    // 3. DELETE ITEM
    #[Route('/{id}/delete', name: 'app_tenant_product_delete', methods: ['POST'])]
    public function delete(Request $request, Product $product, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->request->get('_token'))) {
            $em->remove($product);
            $em->flush();
            $this->addFlash('success', 'Product deleted successfully.');
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('app_tenant_product_index');
    }

    // 4. SMART BULK CSV UPLOAD (Handles "JSS 1" lookup & Duplicate Prevention)
    #[Route('/bulk-upload', name: 'app_tenant_product_bulk', methods: ['POST'])]
    public function bulkUpload(Request $request, EntityManagerInterface $em): Response
    {
        $file = $request->files->get('csv_file');

        if (!$file) {
            $this->addFlash('error', 'Please upload a valid CSV file.');
            return $this->redirectToRoute('app_tenant_product_index');
        }

        if (($handle = fopen($file->getPathname(), "r")) !== FALSE) {
            $countNew = 0;
            $countUpdated = 0;
            
            fgetcsv($handle); // Skip the Excel header row

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Expected CSV Columns: Name | Category | Price | Stock | Level (Optional)
                if (count($data) >= 4) {
                    $name = trim($data[0]);
                    $category = strtoupper(trim($data[1])); 
                    $price = trim($data[2]);
                    $stock = (int)$data[3];
                    $levelName = isset($data[4]) ? trim($data[4]) : ''; // e.g. "JSS 1"

                    if (empty($name)) continue; 

                    // A. DUPLICATE CHECK (Update stock instead of making a clone)
                    $product = $em->getRepository(Product::class)->findOneBy([
                        'name' => $name,
                        'category' => $category
                    ]);

                    if ($product) {
                        $product->setStockQuantity($product->getStockQuantity() + $stock);
                        $product->setUnitPrice($price);
                        $countUpdated++;
                    } else {
                        $product = new Product();
                        $product->setName($name);
                        $product->setCategory($category);
                        $product->setUnitPrice($price);
                        $product->setStockQuantity($stock);
                        $countNew++;
                    }

                    // B. THE SMART LOOKUP (Handling JSS 1 without arms)
                    if (!empty($levelName)) {
                        // The '%' wildcard tells the database: 
                        // Find the first class that STARTS with "JSS 1" (like JSS 1A)
                        $classroom = $em->getRepository(Classroom::class)->createQueryBuilder('c')
                            ->where('c.name LIKE :name')
                            ->setParameter('name', $levelName . '%') 
                            ->setMaxResults(1)
                            ->getQuery()
                            ->getOneOrNullResult();

                        if ($classroom) {
                            $product->setClassroom($classroom); 
                        }
                    }

                    $em->persist($product);
                }
            }
            fclose($handle);
            $em->flush();

            $this->addFlash('success', "Import Complete! $countNew new items added, $countUpdated existing items updated.");
        } else {
            $this->addFlash('error', 'Failed to read the file.');
        }

        return $this->redirectToRoute('app_tenant_product_index');
    }
}