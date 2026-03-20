<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IdpUser;

class IdpUserPasswordManager
{
    public function applyPassword(IdpUser $user, string $password): void
    {
        $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        $user->setNtPasswordHash($this->computeNtPasswordHash($password));
    }

    private function computeNtPasswordHash(string $password): ?string
    {
        try {
            $utf16le = iconv('UTF-8', 'UTF-16LE', $password);
            if ($utf16le === false) {
                return null;
            }

            return strtoupper(hash('md4', $utf16le));
        } catch (\Throwable) {
            return null;
        }
    }
}
