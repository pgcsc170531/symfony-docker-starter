<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\User;
use App\Form\AccountProfileType;
use App\Form\ChangePasswordType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * My account — the one screen every signed-in person owns.
 *
 * Until now a teacher, a bursar or a parent could not change their own password
 * at all: the only password field in the whole tenant app was on the
 * administrator's staff screen. This is that missing door, open to any signed-in
 * account, whatever its role.
 */
#[Route('/account')]
class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('', name: 'app_tenant_account', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();

        return $this->renderPage(
            $user,
            // Set by MustChangePasswordSubscriber when it intercepted the login.
            $user->isMustChangePassword() || $request->query->getBoolean('first'),
        );
    }

    #[Route('/profile', name: 'app_tenant_account_profile', methods: ['POST'])]
    public function saveProfile(Request $request): Response
    {
        $user = $this->currentUser();
        $staff = $user->getStaff();

        // The form is bound to the entity, so handleRequest() overwrites the
        // current address the moment it runs. Keep the old one to know whether
        // the login identity is actually being changed — and to put it back
        // when the submission is rejected: the session token is re-serialised
        // from this same object at the end of the request, so a rejected change
        // that stayed in memory would de-authenticate the person on their very
        // next click.
        $originalEmail = mb_strtolower((string) $user->getEmail());

        $form = $this->createForm(AccountProfileType::class, $user, ['staff' => $staff]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $user->setEmail($originalEmail);

            return $this->renderPage($user, $user->isMustChangePassword(), $form);
        }

        $email = mb_strtolower(trim((string) $user->getEmail()));
        $emailChanged = $email !== $originalEmail;

        if ($emailChanged) {
            // The email IS the login. A session left open on a shared office
            // computer must not be enough to hand the account to somebody else.
            if (!$this->passwordHasher->isPasswordValid($user, (string) $form->get('currentPassword')->getData())) {
                $form->get('currentPassword')->addError(new FormError(
                    'Enter your current password to change your email address.'
                ));

                $user->setEmail($originalEmail);

                return $this->renderPage($user, $user->isMustChangePassword(), $form);
            }

            $clash = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($clash !== null && $clash->getId() !== $user->getId()) {
                $form->get('email')->addError(new FormError(sprintf('%s is already used by another account.', $email)));

                $user->setEmail($originalEmail);

                return $this->renderPage($user, $user->isMustChangePassword(), $form);
            }
        }

        $user->setEmail($email);

        if ($staff !== null) {
            $phone = preg_replace('/\D+/', '', (string) $form->get('phone')->getData()) ?: null;
            $address = trim((string) $form->get('address')->getData());

            $staff->setPhone($phone)
                ->setAddress($address === '' ? null : mb_substr($address, 0, 255));
        }

        $this->em->flush();

        if ($emailChanged) {
            // The email is the login identifier, and Symfony de-authenticates a
            // session whose identifier changed — the person would be thrown to
            // /login the instant they corrected their own address. Re-issue the
            // token with the same user so the session survives the change.
            $current = $this->tokenStorage->getToken();
            if ($current !== null) {
                $this->tokenStorage->setToken(new UsernamePasswordToken(
                    $user,
                    $current->getFirewallName() ?? 'main',
                    $user->getRoles(),
                ));
            }
        }

        $this->addFlash('success', $emailChanged
            ? sprintf('Your details were saved. You now sign in with %s.', $email)
            : 'Your details were saved.');

        return $this->redirectToRoute('app_tenant_account');
    }

    #[Route('/password', name: 'app_tenant_account_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        $user = $this->currentUser();

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderPage($user, $user->isMustChangePassword(), null, $form);
        }

        $current = (string) $form->get('currentPassword')->getData();

        if (!$this->passwordHasher->isPasswordValid($user, $current)) {
            // Not a validation rule: the person may have been handed a temporary
            // password and typed it with a stray space.
            $form->get('currentPassword')->addError(new FormError('That is not your current password.'));

            return $this->renderPage($user, $user->isMustChangePassword(), null, $form);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $form->get('newPassword')->getData()));
        // They chose it, so the door is no longer standing open.
        $user->setMustChangePassword(false);

        $this->em->flush();

        $this->addFlash('success', 'Your password has been changed.');

        return $this->redirectToRoute('app_tenant_account');
    }

    /**
     * One rendering path for every outcome, so the two forms can never disagree
     * about what the page shows — three copies of this array had already drifted.
     */
    private function renderPage(
        User $user,
        bool $forced = false,
        ?FormInterface $profileForm = null,
        ?FormInterface $passwordForm = null,
    ): Response {
        $staff = $user->getStaff();

        return $this->render('tenant/account/index.html.twig', [
            'profileForm' => ($profileForm ?? $this->createForm(
                AccountProfileType::class,
                $user,
                ['staff' => $staff],
            ))->createView(),
            'passwordForm' => ($passwordForm ?? $this->createForm(ChangePasswordType::class))->createView(),
            'isStaff' => $staff !== null,
            'forced' => $forced,
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Only signed-in accounts have a profile.');
        }

        return $user;
    }
}
