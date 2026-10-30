<?php

namespace App\EventSubscriber;

use App\Entity\Tenant\User;
use App\Service\Portal\PortalIdentity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PortalIdentity $identity)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $firewallName = $event->getFirewallName();

        // ⚠️ LoginSuccessEvent fires for EVERY firewall (main = tenant, landlord = /admin,
        // agent = /agent). Only the TENANT firewall sends users to /dashboard.
        //
        // Redirecting the landlord/agent logins here used to override their own
        // default_target_path in security.yaml and send super admins to the tenant
        // /dashboard — which the tenant firewall then bounced to /login:
        //   POST /admin/login → 302 /dashboard → 302 /login  ❌
        if ($firewallName !== 'main') {
            return;
        }

        $request = $event->getRequest();

        // A member of staff whose own children attend the school has one login and
        // two portals. Ask them at the door which one they want; the answer is a
        // session choice, so every sign-in starts by asking again.
        $user = $event->getUser();
        $session = $request->getSession();

        if ($user instanceof User && $this->identity->isDual($user)) {
            $session->set('portal.dual', true);
            $session->remove('portal.mode');
            $event->setResponse(new RedirectResponse($request->getUriForPath('/choose-portal')));

            return;
        }

        $session->remove('portal.dual');
        $session->remove('portal.mode');

        // Target path is stored per-firewall: _security.<firewall>.target_path
        $targetPath = $request->getSession()->get('_security.' . $firewallName . '.target_path');

        if (!$targetPath) {
            $event->setResponse(new RedirectResponse($request->getUriForPath('/dashboard')));
        }
    }
}