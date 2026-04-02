<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IdpUser;
use App\Entity\Tenant;
use App\Repository\IdpUserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TenantLocalCredentialService
{
    public function __construct(
        private readonly IdpUserRepository $idpUserRepository,
        private readonly IdpUserPasswordManager $passwordManager,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function findByIdentifier(Tenant $tenant, string $identifier): ?IdpUser
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }

        $direct = $this->idpUserRepository->findByTenantAndUsername($tenant, $trimmed);
        if ($direct instanceof IdpUser) {
            return $direct;
        }

        $normalized = strtolower($trimmed);
        foreach ($this->idpUserRepository->findBy(['tenant' => $tenant]) as $user) {
            $attributes = $user->getAttributes();
            $aliases = array_filter([
                strtolower($user->getUsername()),
                strtolower((string) ($attributes['mail'][0] ?? '')),
                strtolower((string) ($attributes['eduPersonPrincipalName'][0] ?? '')),
                strtolower((string) ($attributes['uid'][0] ?? '')),
            ]);

            if (in_array($normalized, $aliases, true)) {
                return $user;
            }
        }

        return null;
    }

    public function verifyPassword(IdpUser $user, string $plainPassword): bool
    {
        if (password_verify($plainPassword, $user->getPassword())) {
            return true;
        }

        $legacySalt = $user->getLegacySalt();
        if ($legacySalt === null || $legacySalt === '') {
            return false;
        }

        if (!$this->verifyLegacySaltedHash($user->getPassword(), $plainPassword, $legacySalt)) {
            return false;
        }

        $this->passwordManager->applyPassword($user, $plainPassword);
        $user->setLegacySalt(null);
        $this->entityManager->flush();

        return true;
    }

    private function verifyLegacySaltedHash(string $encoded, string $plainPassword, string $salt): bool
    {
        $digest = openssl_digest($plainPassword . $salt, 'sha512', true);
        if ($digest === false) {
            return false;
        }

        return hash_equals($encoded, base64_encode($digest . $salt));
    }
}
