<?php

namespace App\EventListener;

use App\Entity\Landlord\School;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 500)]
class TenantListener
{
    public function __construct(
        private ManagerRegistry $registry,
        private Connection $defaultConnection
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = $request->getHost(); // e.g., "hopehigh.localhost"

        // 1. Identify the Subdomain
        // Remove ".localhost" or your domain to get just the "hopehigh" part
        $parts = explode('.', $host);
        $subdomain = $parts[0];

        // If visiting the main domain (e.g. "localhost"), do nothing (Landlord Mode)
        if ($subdomain === 'localhost' || $subdomain === 'www') {
            return;
        }

        // 2. Find the School in the Landlord DB
        $landlordEm = $this->registry->getManager('landlord');
        $school = $landlordEm->getRepository(School::class)->findOneBy(['subdomain' => $subdomain]);

        if (!$school) {
            throw new NotFoundHttpException("School '$subdomain' not found.");
        }

        if (!$school->isActive()) {
            throw new NotFoundHttpException("This school is currently inactive.");
        }

        // 3. THE MAGIC: Switch the Database Connection
        // We close the current connection and change the 'dbname' parameter
        $this->defaultConnection->close();
        
        $params = $this->defaultConnection->getParams();
        $params['dbname'] = $school->getDatabaseName();
        
        // Use Reflection to force the new params (Symfony safety bypass)
        $reflector = new \ReflectionProperty(Connection::class, 'params');
        $reflector->setAccessible(true);
        $reflector->setValue($this->defaultConnection, $params);
        
        // The next query will automatically connect to the new DB!
    }
}