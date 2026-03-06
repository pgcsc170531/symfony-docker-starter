<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\FeeItem;
use App\Form\FeeItemType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/items')]
class FeeItemController extends AbstractController
{
    #[Route('/', name: 'app_tenant_fee_item_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        // 1. Handle "Create New" Form
        $feeItem = new FeeItem();
        $form = $this->createForm(FeeItemType::class, $feeItem);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $feeItem->setCreatedAt(new \DateTimeImmutable());
            
            $em->persist($feeItem);
            $em->flush();

            $this->addFlash('success', 'Fee Item created successfully!');
            return $this->redirectToRoute('app_tenant_fee_item_index');
        }

        // 2. Fetch the List
        $items = $em->getRepository(FeeItem::class)->findAll();

        return $this->render('tenant/fee/item_index.html.twig', [
            'items' => $items,
            'form' => $form,
        ]);
    }
}