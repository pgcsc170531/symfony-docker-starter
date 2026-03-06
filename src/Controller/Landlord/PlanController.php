<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\Plan;
use App\Form\Landlord\PlanType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/plan', name: 'landlord_plan_')]
#[IsGranted('ROLE_SUPER_ADMIN')] // Ensure only you can access this
class PlanController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        $plans = $doctrine->getManager('landlord')
            ->getRepository(Plan::class)
            ->findAll();

        return $this->render('landlord/plan/index.html.twig', [
            'plans' => $plans,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, ManagerRegistry $doctrine): Response
    {
        $plan = new Plan();
        $form = $this->createForm(PlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager('landlord');
            $em->persist($plan);
            $em->flush();

            $this->addFlash('success', 'New Subscription Plan created!');
            return $this->redirectToRoute('landlord_plan_index');
        }

        return $this->render('landlord/plan/new.html.twig', [
            'plan' => $plan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Plan $plan, ManagerRegistry $doctrine): Response
    {
        $form = $this->createForm(PlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager('landlord')->flush();

            $this->addFlash('success', 'Plan updated successfully.');
            return $this->redirectToRoute('landlord_plan_index');
        }

        return $this->render('landlord/plan/edit.html.twig', [
            'plan' => $plan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Plan $plan, ManagerRegistry $doctrine): Response
    {
        if ($this->isCsrfTokenValid('delete'.$plan->getId(), $request->request->get('_token'))) {
            $em = $doctrine->getManager('landlord');
            $em->remove($plan);
            $em->flush();
            $this->addFlash('success', 'Plan deleted.');
        }

        return $this->redirectToRoute('landlord_plan_index');
    }
}