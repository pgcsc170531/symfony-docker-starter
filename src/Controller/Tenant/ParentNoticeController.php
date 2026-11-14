<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\FamilyLinkNotice;
use App\Entity\Tenant\Guardian;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The parent's answer to "a pupil has been added to your family".
 *
 * The change is made by the school office, but the family is the only party who
 * can say whether it is right. A parent either confirms it, or writes a note
 * that appears in the bursar's family screens. Silence is not consent — the
 * notice stays open until they answer.
 */
#[Route('/parent/notices')]
#[IsGranted('ROLE_PARENT')]
final class ParentNoticeController extends AbstractController
{
    #[Route('/{id}/confirm', name: 'app_parent_notice_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(FamilyLinkNotice $notice, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertOwnedByCurrentParent($notice, $em);

        if (!$this->isCsrfTokenValid('confirm_notice_' . $notice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        if (!$notice->isPending()) {
            $this->addFlash('warning', 'That family update has already been answered.');

            return $this->redirectToRoute('app_parent_dashboard');
        }

        $notice->confirm();
        $em->flush();

        $this->addFlash('success', sprintf(
            'Thank you — %s is confirmed as part of your family.',
            $notice->getStudent()?->getFullName() ?? 'the pupil'
        ));

        return $this->redirectToRoute('app_parent_dashboard');
    }

    #[Route('/{id}/report', name: 'app_parent_notice_report', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function report(FamilyLinkNotice $notice, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertOwnedByCurrentParent($notice, $em);

        if (!$this->isCsrfTokenValid('report_notice_' . $notice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        if (!$notice->isPending()) {
            $this->addFlash('warning', 'That family update has already been answered.');

            return $this->redirectToRoute('app_parent_dashboard');
        }

        $note = trim((string) $request->request->get('note'));

        if ($note === '') {
            $this->addFlash('error', 'Write a short note so the school knows what is wrong, then send it.');

            return $this->redirect($this->generateUrl('app_parent_dashboard') . '#family-notices');
        }

        $notice->report($note);
        $em->flush();

        $this->addFlash('success', 'Your note has been sent to the school office. They will review the link.');

        return $this->redirectToRoute('app_parent_dashboard');
    }

    private function assertOwnedByCurrentParent(FamilyLinkNotice $notice, EntityManagerInterface $em): void
    {
        /** @var \App\Entity\Tenant\User|null $user */
        $user = $this->getUser();
        $guardian = $user !== null
            ? $em->getRepository(Guardian::class)->findOneBy(['user' => $user])
            : null;

        if ($guardian === null || $notice->getGuardian()?->getId() !== $guardian->getId()) {
            throw $this->createAccessDeniedException('That family update does not belong to you.');
        }
    }
}
