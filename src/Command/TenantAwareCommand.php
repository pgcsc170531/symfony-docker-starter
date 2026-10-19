<?php

namespace App\Command;

use App\Entity\Landlord\School as LandlordSchool;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Base for commands that must run once per school.
 *
 * The HTTP kernel switches the tenant database from the request host
 * ({@see \App\EventListener\TenantListener}). A console command has no host, so
 * it does the same thing explicitly: point the default connection at each
 * school's database, clear the EntityManager, run the work.
 */
abstract class TenantAwareCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        protected readonly EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        protected readonly Connection $tenantConnection,
        protected readonly EntityManagerInterface $tenantEm,
    ) {
        parent::__construct();
    }

    /** @return LandlordSchool[] */
    protected function schools(?string $subdomain = null): array
    {
        $criteria = [];
        if ($subdomain !== null && trim($subdomain) !== '') {
            $criteria['subdomain'] = trim($subdomain);
        }

        return array_values(array_filter(
            $this->landlordEm->getRepository(LandlordSchool::class)->findBy($criteria, ['name' => 'ASC']),
            static fn (LandlordSchool $school): bool => (bool) $school->isActive()
        ));
    }

    protected function switchTo(LandlordSchool $school): void
    {
        $this->tenantConnection->close();

        $params = $this->tenantConnection->getParams();
        $params['dbname'] = $school->getDatabaseName();

        $reflection = new \ReflectionObject($this->tenantConnection);
        $property = $reflection->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($this->tenantConnection, $params);

        // Drop any entities from the previous school before reading this one.
        $this->tenantEm->clear();
    }
}
