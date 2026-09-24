<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginRedirectSubscriber implements EventSubscriberInterface
{
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

        // Target path is stored per-firewall: _security.<firewall>.target_path
        $targetPath = $request->getSession()->get('_security.' . $firewallName . '.target_path');

        if (!$targetPath) {
            $event->setResponse(new RedirectResponse($request->getUriForPath('/dashboard')));
        }
    }
}