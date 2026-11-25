<?php

namespace App\Controller\Tenant;

use App\Service\Security\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two public pages behind "I forgot my password".
 *
 * No login required — that is the whole point — so both paths are PUBLIC_ACCESS
 * in security.yaml. The reset link is the only credential: 64 hex characters,
 * single use, one hour, and only ever delivered by email.
 */
#[Route('')]
final class PasswordResetController extends AbstractController
{
    public function __construct(private readonly PasswordResetService $reset)
    {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $sent = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Please try again.');

                return $this->redirectToRoute('app_forgot_password');
            }

            // The answer never depends on the result: telling an anonymous
            // visitor "no account here" would turn this page into a directory.
            $sent = true;
            $this->reset->request((string) $request->request->get('contact'));
        }

        return $this->render('security/forgot_password.html.twig', [
            'sent' => $sent,
        ]);
    }

    #[Route(
        '/reset-password/{token}',
        name: 'app_reset_password',
        methods: ['GET', 'POST'],
        requirements: ['token' => '[a-f0-9]{64}'],
    )]
    public function reset(string $token, Request $request): Response
    {
        $user = $this->reset->userForToken($token);

        if ($user === null) {
            return $this->render('security/reset_password.html.twig', [
                'token' => null,
                'email' => null,
                'error' => null,
            ]);
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', (string) $request->request->get('_token'))) {
                $error = 'That form expired. Please try again.';
            } else {
                $password = (string) $request->request->get('password');
                $confirmation = (string) $request->request->get('password_confirm');

                if (mb_strlen($password) < PasswordResetService::minimumLength()) {
                    $error = sprintf('Use at least %d characters.', PasswordResetService::minimumLength());
                } elseif ($password !== $confirmation) {
                    $error = 'The two passwords do not match.';
                } else {
                    $this->reset->reset($user, $password);

                    $this->addFlash('success', 'Your password has been changed. Sign in with it now.');

                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
            'email' => $user->getEmail(),
            'error' => $error,
        ]);
    }
}
