<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceProvider;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceProvider>
 */
class ServiceProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceProvider::class);
    }

    /**
     * @return ServiceProvider[]
     */
    public function findApprovedByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('sp')
            ->where('sp.tenant = :tenant')
            ->andWhere('sp.approved = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('sp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param Tenant[] $tenants
     *
     * @return ServiceProvider[]
     */
    public function findApprovedByTenants(array $tenants): array
    {
        if ($tenants === []) {
            return [];
        }

        return $this->createQueryBuilder('sp')
            ->where('sp.tenant IN (:tenants)')
            ->andWhere('sp.approved = true')
            ->setParameter('tenants', $tenants)
            ->orderBy('sp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find SPs whose certificates expire within $days days.
     *
     * @return ServiceProvider[]
     */
    public function findExpiringSoon(int $days = 30): array
    {
        $threshold = (new \DateTimeImmutable())->modify("+{$days} days");
        return $this->createQueryBuilder('sp')
            ->where('sp.certificateExpiresAt IS NOT NULL')
            ->andWhere('sp.certificateExpiresAt <= :threshold')
            ->andWhere('sp.certificateExpiresAt >= :now')
            ->setParameter('threshold', $threshold)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('sp.certificateExpiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ServiceProvider[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('sp')
            ->where('sp.certificateExpiresAt IS NOT NULL')
            ->andWhere('sp.certificateExpiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('sp')
            ->select('COUNT(sp.id)')
            ->where('sp.tenant = :tenant')
            ->andWhere('sp.approved = true')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(ServiceProvider $sp, bool $flush = false): void
    {
        $this->getEntityManager()->persist($sp);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
