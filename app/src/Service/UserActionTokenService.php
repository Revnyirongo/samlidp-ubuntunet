<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserActionToken;
use App\Repository\UserActionTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class UserActionTokenService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserActionTokenRepository $repo,
    ) {}

    public function issue(User $user, string $purpose, \DateInterval $ttl = new \DateInterval('PT1H')): string
    {
        $now = new \DateTimeImmutable();

        foreach ($this->repo->findActiveByUserAndPurpose($user, $purpose) as $existing) {
            $existing->setUsedAt($now);
        }

        $rawToken = bin2hex(random_bytes(32));
        $token = (new UserActionToken())
            ->setUser($user)
            ->setPurpose($purpose)
            ->setTokenHash(hash('sha256', $rawToken))
            ->setExpiresAt($now->add($ttl));

        $this->em->persist($token);
        $this->em->flush();

        return $rawToken;
    }

    public function findValid(string $rawToken, string $purpose): ?UserActionToken
    {
        return $this->repo->findValidByHashAndPurpose(hash('sha256', $rawToken), $purpose);
    }

    public function consume(string $rawToken, string $purpose): ?UserActionToken
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
