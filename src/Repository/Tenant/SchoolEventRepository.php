<?php

namespace App\Repository\Tenant;

use App\Entity\Tenant\SchoolEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolEvent>
 *
 * @method SchoolEvent|null find($id, $lockMode = null, $lockVersion = null)
 * @method SchoolEvent|null findOneBy(array $criteria, array $orderBy = null)
 * @method SchoolEvent[]    findAll()
 * @method SchoolEvent[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SchoolEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        // 💡 This tells Doctrine: "I am the repo for SchoolEvent"
        parent::__construct($registry, SchoolEvent::class);
    }

    public function save(SchoolEvent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SchoolEvent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}