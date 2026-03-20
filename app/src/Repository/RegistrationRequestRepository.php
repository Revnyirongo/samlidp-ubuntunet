<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RegistrationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistrationRequest>
 */
class RegistrationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistrationRequest::class);
    }

    public function findPendingByEmail(string $email): ?RegistrationRequest
    {
        return $this->findOneBy([
            'email' => strtolower(trim($email)),
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);
    }
}
