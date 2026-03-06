<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Product;
use App\Form\ProductType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/store/products')]
class ProductController extends AbstractController
{
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
}