<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\User;
use App\Service\Portal\PortalIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Which hat are you wearing today?"
 *
 * Some members of staff are also parents here — they teach or keep the books, and
 * their own children are in the school. One login, two jobs, and only one of them
 * at a time: the parent portal has a four-tab shell and no staff navigation, so
 * being dumped into it would hide the work they came to do.
 *
 * The choice lives in the session, never in the database. It is asked once per
 * sign-in, and can be changed afterwards from the header of either portal.
 */
#[Route('/choose-portal', name: 'app_portal_')]
#[IsGranted('ROLE_USER')]
final class PortalChoiceController extends AbstractController
{
    #[Route('', name: 'choose', methods: ['GET', 'POST'])]
    public function choose(Request $request, PortalIdentity $identity): Response
    {
        $user = $this->getUser();
        $session = $request->getSession();

        // Everyone else goes straight through; a stale flag must not strand them.
        if (!$user instanceof User || !$identity->isDual($user)) {
            $session->remove('portal.mode');
            $session->remove('portal.dual');

            return $this->redirectToRoute('app_tenant_dashboard');
        }

        $session->set('portal.dual', true);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('portal_mode', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Please choose again.');

                return $this->redirectToRoute('app_portal_choose');
            }

            $mode = $identity->modeFrom((string) $request->request->get('mode'));
            $session->set('portal.mode', $mode);

            // The role is added on the NEXT request, by TenantUserProvider, so the
            // destination is chosen before the token is rebuilt.
            return $this->redirectToRoute(
                $mode === 'parent' ? 'app_parent_dashboard' : 'app_tenant_dashboard'
            );
        }

        return $this->render('tenant/portal/choose.html.twig', [
            'guardian' => $identity->guardianOf($user),
            'current' => $session->get('portal.mode'),
        ]);
    }
}
