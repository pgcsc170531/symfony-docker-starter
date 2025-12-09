<?php

namespace App\EventListener;

use App\Entity\Landlord\School;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Doctrine\DBAL\Connection;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
class TenantListener
{
    public function __construct(
        // We need the Landlord DB to find the school
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,
        
        // We need the Default (Tenant) Connection to switch it
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $tenantConnection
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = $request->getHost(); // e.g., "faith.localhost"

        // 1. Check if we are on the Main Domain (Landlord Admin)
        // Adjust 'localhost' to whatever your main domain logic is.
        // If the host IS localhost (no subdomain), we do nothing (stay in Landlord mode logic)
        if ($host === 'localhost' || $host === 'www.localhost') {
            return;
        }

        // 2. Extract Subdomain
        // faith.localhost -> parts[0] = faith
        $parts = explode('.', $host);
        $subdomain = $parts[0];

        // 3. Find the School in Landlord DB
        $school = $this->landlordEm->getRepository(School::class)->findOneBy(['subdomain' => $subdomain]);

        if (!$school) {
            throw new NotFoundHttpException("School '$subdomain' not found.");
        }

        if (!$school->isActive()) {
            throw new NotFoundHttpException("This school is currently inactive.");
        }

        // 4. THE MAGIC: Switch the Database Connection
        // We close the generic connection and point it to the specific DB
        $this->tenantConnection->close();
        
        // We override the 'dbname' parameter
        $params = $this->tenantConnection->getParams();
        $params['dbname'] = $school->getDatabaseName(); // e.g. 'school_faith'
        
        // We reopen the connection with the new params
        // Note: Doctrine prevents setting params on an open connection, so we use a Reflection hack
        // or the specific Doctrine method if available. 
        // For simplicity in this stack, we can re-instantiate or use this trick:
        
        $reflector = new \ReflectionObject($this->tenantConnection);
        $property = $reflector->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($this->tenantConnection, $params);
    }
}