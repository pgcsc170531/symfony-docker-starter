<?php

namespace App\EventListener;

use App\Entity\Landlord\School;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Doctrine\DBAL\Connection;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
class TenantListener
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,
        
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $tenantConnection
    ) {}

public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = strtolower($request->getHost()); 
        
        // 1. Get the Base Domain (Ensure it matches your .env)
        $baseDomain = strtolower($_ENV['BASE_DOMAIN'] ?? 'localhost');

        // 2. LANDLORD CHECK: If host is exactly the base domain, STOP.
        // This prevents edus.ng from being processed as a tenant.
        if ($host === $baseDomain || $host === "www.$baseDomain" || $host === 'localhost') {
            return; 
        }

        // 3. SUBDOMAIN EXTRACTION
        // We remove the base domain to get the school name
        if (str_contains($host, '.' . $baseDomain)) {
            $subdomain = str_replace('.' . $baseDomain, '', $host);
        } else {
            // If it's not a subdomain and not the base domain, it's a landlord request
            return; 
        }

        // 4. DATABASE LOOKUP
        $school = $this->landlordEm->getRepository(School::class)->findOneBy(['subdomain' => $subdomain]);

        if (!$school) {
            // If you reach here on edus.ng, it means the check in Step 2 FAILED.
            throw new NotFoundHttpException("School '$subdomain' not found on $host. Expected Base: $baseDomain");
        }

        if (!$school->isActive()) {
            throw new NotFoundHttpException("This school is currently inactive.");
        }

        // 5. SWITCH CONNECTION
        $this->tenantConnection->close();
        
        $params = $this->tenantConnection->getParams();
        $params['dbname'] = $school->getDatabaseName(); 
        
        $reflector = new \ReflectionObject($this->tenantConnection);
        $property = $reflector->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($this->tenantConnection, $params);
    }
}