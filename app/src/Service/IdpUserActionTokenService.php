<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IdpUser;
use App\Entity\IdpUserActionToken;
use App\Repository\IdpUserActionTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class IdpUserActionTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdpUserActionTokenRepository $repo,
    ) {}

    public function issue(IdpUser $user, string $purpose, \DateInterval $ttl = new \DateInterval('PT1H')): string
    {
        $now = new \DateTimeImmutable();

        foreach ($this->repo->findActiveByUserAndPurpose($user, $purpose) as $existing) {
            $existing->setUsedAt($now);
        }

        $rawToken = bin2hex(random_bytes(32));
        $token = (new IdpUserActionToken())
            ->setUser($user)
            ->setPurpose($purpose)
            ->setTokenHash(hash('sha256', $rawToken))
            ->setExpiresAt($now->add($ttl));

        $this->em->persist($token);
        $this->em->flush();

        return $rawToken;
    }

    public function findValid(string $rawToken, string $purpose): ?IdpUserActionToken
    {
        return $this->repo->findValidByHashAndPurpose(hash('sha256', $rawToken), $purpose);
    }

    public function consume(string $rawToken, string $purpose): ?IdpUserActionToken
    {
        $token = $this->findValid($rawToken, $purpose);
        if ($token === null) {
            return null;
        }

        $token->setUsedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $token;
    }
}
