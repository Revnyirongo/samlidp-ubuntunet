<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdpUser;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IdpUser>
 */
class IdpUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdpUser::class);
    }

    public function findByTenantAndUsername(Tenant $tenant, string $username): ?IdpUser
    {
        return $this->findOneBy(['tenant' => $tenant, 'username' => $username]);
    }

    public function findByTenantAndEmail(Tenant $tenant, string $email): ?IdpUser
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        /** @var IdpUser[] $users */
        $users = $this->createQueryBuilder('u')
            ->where('u.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            if (strtolower((string) $user->getEmail()) === $normalized) {
                return $user;
            }
        }

        return null;
    }

    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(IdpUser $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
