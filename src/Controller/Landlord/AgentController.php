<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\Agent;
use App\Form\Landlord\AgentType;
use Doctrine\Persistence\ManagerRegistry; 
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/agent', name: 'landlord_agent_')]
#[IsGranted('ROLE_SUPER_ADMIN')] // 🛡️ Security: Only Landlord can access
class AgentController extends AbstractController
{
    // ======================================================
    // 1. LIST AGENTS
    // ======================================================
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        $agents = $doctrine
            ->getManager('landlord')
            ->getRepository(Agent::class)
            ->findAll();

        return $this->render('landlord/agent/index.html.twig', [
            'agents' => $agents,
        ]);
    }

    // ======================================================
    // 2. CREATE NEW AGENT
    // ======================================================
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request, 
        ManagerRegistry $doctrine, 
        UserPasswordHasherInterface $passwordHasher
    ): Response
    {
        $agent = new Agent();
        $form = $this->createForm(AgentType::class, $agent);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $landlordEm = $doctrine->getManager('landlord');

            // Hash the password
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($agent, $plainPassword);
                $agent->setPassword($hashedPassword);
            }
            
            $landlordEm->persist($agent);
            $landlordEm->flush();

            $this->addFlash('success', 'Agent created successfully!');
            return $this->redirectToRoute('landlord_agent_index');
        }

        return $this->render('landlord/agent/new.html.twig', [
            'form' => $form,
            'agent' => $agent // Pass variable for template title
        ]);
    }

    // ======================================================
    // 3. SHOW AGENT PROFILE (New)
    // ======================================================
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Agent $agent): Response
    {
        // Shows the profile page with the list of their schools
        return $this->render('landlord/agent/show.html.twig', [
            'agent' => $agent,
        ]);
    }

    // ======================================================
    // 4. EDIT AGENT (New)
    // ======================================================
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request, 
        Agent $agent, 
        ManagerRegistry $doctrine,
        UserPasswordHasherInterface $passwordHasher
    ): Response
    {
        $form = $this->createForm(AgentType::class, $agent);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            // Optional: Handle Password Update only if filled
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($agent, $plainPassword);
                $agent->setPassword($hashedPassword);
            }

            $doctrine->getManager('landlord')->flush();

            $this->addFlash('success', 'Agent profile updated.');
            return $this->redirectToRoute('landlord_agent_show', ['id' => $agent->getId()]);
        }

        return $this->render('landlord/agent/edit.html.twig', [
            'agent' => $agent,
            'form' => $form,
        ]);
    }

    // ======================================================
    // 5. DELETE AGENT (New)
    // ======================================================
    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Agent $agent, ManagerRegistry $doctrine): Response
    {
        if ($this->isCsrfTokenValid('delete'.$agent->getId(), $request->request->get('_token'))) {
            
            // Safety Check: Don't delete if they have schools
            if (!$agent->getSchools()->isEmpty()) {
                $this->addFlash('error', 'Cannot delete Agent. They still have active schools assigned.');
                return $this->redirectToRoute('landlord_agent_show', ['id' => $agent->getId()]);
            }

            $em = $doctrine->getManager('landlord');
            $em->remove($agent);
            $em->flush();
            
            $this->addFlash('success', 'Agent account deleted.');
        }

        return $this->redirectToRoute('landlord_agent_index');
    }
}