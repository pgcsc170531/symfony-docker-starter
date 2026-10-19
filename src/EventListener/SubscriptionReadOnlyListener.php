<?php

namespace App\EventListener;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\User as TenantUser;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * "Expired means read-only, never deleted" — enforced at the door.
 *
 * A school whose subscription has lapsed can still READ everything (reports,
 * invoices, exports) and can still reach the billing screens to renew. Anything
 * that would write — create, edit, delete, upload, send — is refused before it
 * reaches a controller, so no half-finished form can slip a write through.
 *
 * Free/trial accounts with no Subscription row are left alone on purpose: this
 * listener exists to stop an unpaid account mutating data, not to invent a paywall.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final class SubscriptionReadOnlyListener
{
    /** Writing methods only; GET/HEAD/OPTIONS are always allowed. */
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Everything a lapsed school must still be able to do.
     * `/subscription` includes register + Paystack callbacks.
     */
    private const ALLOWED_PREFIXES = [
        '/login',
        '/logout',
        '/subscription',
        '/register',
        '/apply',
        '/payments',
        '/verify',
        '/r/',
        '/pv/',
        '/p/performance',
        '/_profiler',
        '/_wdt',
    ];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $user = $this->security->getUser();
        if (!$user instanceof TenantUser) {
            return;
        }

        $school = $this->landlordSchool($user);
        if ($school === null) {
            return;
        }

        $subscription = $school->getSubscription();
        if ($subscription === null || $subscription->isValid()) {
            return;
        }

        // Flagged for the banner in base.html.twig (applies to GETs too).
        $request->attributes->set('subscription_expired', true);

        if (!in_array($request->getMethod(), self::MUTATING, true)) {
            return;
        }

        $path = $request->getPathInfo();
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $message = 'Your subscription has ended. This account is read-only until a term is registered.';

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            $event->setResponse(new JsonResponse(['error' => $message], 403));

            return;
        }

        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('warning', $message);
        }

        $event->setResponse(new RedirectResponse(
            $this->urls->generate('tenant_subscription_index')
        ));
    }

    private function landlordSchool(TenantUser $user): ?LandlordSchool
    {
        $tenantSchool = $user->getSchool();
        if ($tenantSchool === null) {
            return null;
        }

        $em = $this->doctrine->getManager('landlord');
        $repository = $em->getRepository(LandlordSchool::class);

        $landlordId = method_exists($tenantSchool, 'getLandlordSchoolId')
            ? $tenantSchool->getLandlordSchoolId()
            : null;

        if ($landlordId !== null) {
            $school = $repository->find($landlordId);
            if ($school !== null) {
                return $school;
            }
        }

        $subdomain = $tenantSchool->getSubdomain();

        return $subdomain !== null && $subdomain !== ''
            ? $repository->findOneBy(['subdomain' => $subdomain])
            : null;
    }
}
