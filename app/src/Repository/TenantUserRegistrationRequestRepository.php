<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TenantUserRegistrationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantUserRegistrationRequest>
 */
class TenantUserRegistrationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantUserRegistrationRequest::class);
    }

    public function findPendingByTenantAndEmail(Tenant $tenant, string $email): ?TenantUserRegistrationRequest
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'email' => strtolower(trim($email)),
            'status' => TenantUserRegistrationRequest::STATUS_PENDING,
        ]);
    }

    public function findPendingByTenantAndUsername(Tenant $tenant, string $username): ?TenantUserRegistrationRequest
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'username' => trim($username),
            'status' => TenantUserRegistrationRequest::STATUS_PENDING,
        ]);
    }
}
