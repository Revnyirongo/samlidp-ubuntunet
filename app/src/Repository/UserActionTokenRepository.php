<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserActionToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserActionToken>
 */
class UserActionTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserActionToken::class);
    }

    public function findValidByHashAndPurpose(string $tokenHash, string $purpose): ?UserActionToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.tokenHash = :tokenHash')
            ->andWhere('t.purpose = :purpose')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return UserActionToken[]
     */
    public function findActiveByUserAndPurpose(User $user, string $purpose): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.purpose = :purpose')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
