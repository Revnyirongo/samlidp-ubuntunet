<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findActiveBySlug(string $slug): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->where('t.slug = :slug')
            ->andWhere('t.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', Tenant::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Tenant[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['status' => Tenant::STATUS_ACTIVE], ['name' => 'ASC']);
    }

    /**
     * @return Tenant[]
     */
    public function findDueForMetadataRefresh(\DateTimeImmutable $olderThan): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :active')
            ->andWhere('t.metadataAggregateUrls != :empty')
            ->andWhere('t.metadataLastRefreshed IS NULL OR t.metadataLastRefreshed < :threshold')
            ->setParameter('active', Tenant::STATUS_ACTIVE)
            ->setParameter('empty', '[]')
            ->setParameter('threshold', $olderThan)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(): array
    {
        $results = $this->createQueryBuilder('t')
            ->select('t.status, COUNT(t.id) as cnt')
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $map = [
            Tenant::STATUS_ACTIVE    => 0,
            Tenant::STATUS_PENDING   => 0,
            Tenant::STATUS_SUSPENDED => 0,
        ];
        foreach ($results as $row) {
            $map[$row['status']] = (int) $row['cnt'];
        }
        return $map;
    }

    public function save(Tenant $tenant, bool $flush = false): void
    {
        $this->getEntityManager()->persist($tenant);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
