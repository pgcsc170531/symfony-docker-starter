<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\FeeStructure;
use App\Form\FeeStructureType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/structure')]
class FeeStructureController extends AbstractController
{
    #[Route('/', name: 'app_tenant_fee_structure_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $structure = new FeeStructure();
        $form = $this->createForm(FeeStructureType::class, $structure);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($structure);
            $em->flush();

            $this->addFlash('success', 'Fee assigned successfully!');
            return $this->redirectToRoute('app_tenant_fee_structure_index');
        }

        // Fetch all structures ordered by Class
        $structures = $em->getRepository(FeeStructure::class)->findBy([], ['classroom' => 'ASC']);

        return $this->render('tenant/fee/structure_index.html.twig', [
            'structures' => $structures,
            'form' => $form,
        ]);
    }
}