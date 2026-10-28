<?php

namespace App\EventSubscriber;

use App\Entity\Tenant\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Holds a person at the door until they pick their own password.
 *
 * Accounts created by the office — a staff member added from the directory, or a
 * whole CSV of them — start with a password somebody else chose (and, for an
 * import, with the *same* password for everybody). Without this, the school's
 * default password stays a working key on every one of those accounts forever.
 *
 * A kernel.request guard rather than a login hook on purpose: it also catches a
 * session that is already open when the flag is set, and it survives any future
 * way of signing in (remember-me, impersonation) without being wired in twice.
 */
class MustChangePasswordSubscriber implements EventSubscriberInterface
{
    /**
     * Routes that must stay reachable, or the redirect loops forever.
     */
    private const ALLOWED_ROUTES = [
        'app_tenant_account',          // the profile + password screen itself
        'app_tenant_account_profile',  // saving the profile
        'app_tenant_account_password', // setting the new password
        'app_logout',
        'app_login',
        '_preview_error',
        '_wdt',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After TenantListener (4096), which switches the Doctrine connection to
        // the school's database, and after the firewall has set the token.
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        // Only tenant accounts carry the flag; landlord and agent users are not
        // created with a shared password and must not be intercepted.
        if (!$user instanceof User || !$user->isMustChangePassword()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        // Symfony's own endpoints (profiler, toolbar, error preview) and anything
        // the front controller serves from /build or /assets.
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/_') || preg_match('#^/(build|assets|uploads|images)/#', $path) === 1) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_tenant_account', ['first' => 1])
        ));
    }
}
