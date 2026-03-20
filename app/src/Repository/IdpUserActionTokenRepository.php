<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdpUser;
use App\Entity\IdpUserActionToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IdpUserActionToken>
 */
class IdpUserActionTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdpUserActionToken::class);
    }

    public function findValidByHashAndPurpose(string $tokenHash, string $purpose): ?IdpUserActionToken
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
     * @return IdpUserActionToken[]
     */
    public function findActiveByUserAndPurpose(IdpUser $user, string $purpose): array
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
