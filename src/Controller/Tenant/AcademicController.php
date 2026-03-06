<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Session;
use App\Entity\Tenant\Term;
use App\Form\SessionType;
use App\Form\TermType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/academic')]
class AcademicController extends AbstractController
{
    #[Route('/', name: 'app_tenant_academic_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $sessions = $entityManager->getRepository(Session::class)->findAll();
        return $this->render('tenant/academic/index.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    #[Route('/session/new', name: 'app_tenant_session_new', methods: ['GET', 'POST'])]
    public function newSession(Request $request, EntityManagerInterface $em): Response
    {
        $session = new Session();
        $form = $this->createForm(SessionType::class, $session);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // If this new session is active, turn off all others
            if ($session->isActive()) {
                $this->deactivateAll($em, Session::class);
            }

            $em->persist($session);
            $em->flush();

            $this->addFlash('success', 'Session created successfully!');
            return $this->redirectToRoute('app_tenant_academic_index');
        }

        return $this->render('tenant/academic/new_session.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/session/{id}/term/new', name: 'app_tenant_term_new', methods: ['GET', 'POST'])]
    public function newTerm(Request $request, Session $session, EntityManagerInterface $em): Response
    {
        $term = new Term();
        $term->setSession($session);
        
        $form = $this->createForm(TermType::class, $term);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($term->isActive()) {
                // Deactivate other terms
                $this->deactivateAll($em, Term::class);
                
                // Ensure parent session is active too
                $session->setIsActive(true);
                // We need to deactivate other SESSIONS manually here if we switch session
                $this->deactivateAll($em, Session::class, $session->getId());
            }

            $em->persist($term);
            $em->flush();

            $this->addFlash('success', 'Term added successfully!');
            return $this->redirectToRoute('app_tenant_academic_index');
        }

        return $this->render('tenant/academic/new_term.html.twig', [
            'form' => $form,
            'session' => $session
        ]);
    }

    // UPDATED: Using SQL UPDATE is safer than looping through PHP objects
    private function deactivateAll(EntityManagerInterface $em, string $entityClass, ?int $excludeId = null): void
    {
        $qb = $em->createQueryBuilder();
        $query = $qb->update($entityClass, 'e')
            ->set('e.isActive', 'false'); // Set all to false

        if ($excludeId) {
            $query->where('e.id != :excludeId')
                  ->setParameter('excludeId', $excludeId);
        }

        $query->getQuery()->execute();
    }
}