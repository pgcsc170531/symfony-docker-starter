<?php

namespace App\Service\Tenant;

use App\Entity\Landlord\School;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Points the default (tenant) connection at one school's database, mid-request.
 *
 * The HTTP kernel does this from the request host
 * ({@see \App\EventListener\TenantListener}); a console command or a webhook that
 * arrives on the *platform* domain has no tenant host, so it does it explicitly.
 *
 * Same mechanics as `TenantAwareCommand::switchTo()`: close, rewrite the
 * connection params, clear the EntityManager so no entity from the previous
 * school is read back against the wrong database.
 */
final class TenantConnectionSwitcher
{
    /** Where the connection pointed before the first switch, so it can go back. */
    private ?string $previousDbName = null;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
    ) {
    }

    public function switchTo(School $school): void
    {
        $params = $this->connection->getParams();

        if ($this->previousDbName === null) {
            $this->previousDbName = (string) ($params['dbname'] ?? '');
        }

        $this->connection->close();
        $params['dbname'] = $school->getDatabaseName();

        $reflection = new \ReflectionObject($this->connection);
        $property = $reflection->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($this->connection, $params);

        $this->tenantEm->clear();
    }

    /**
     * Put the default connection back where it was — for a request that only
     * needed to touch one school's database (e.g. the operator creating a
     * subaccount from the landlord screen) and then carries on as the platform.
     */
    public function restore(): void
    {
        if ($this->previousDbName === null) {
            return;
        }

        $this->connection->close();
        $params = $this->connection->getParams();
        $params['dbname'] = $this->previousDbName;

        $reflection = new \ReflectionObject($this->connection);
        $property = $reflection->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($this->connection, $params);

        $this->previousDbName = null;
        $this->tenantEm->clear();
    }
}
