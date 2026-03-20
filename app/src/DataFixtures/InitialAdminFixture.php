<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the first super-admin user.
 * Run once on fresh install:  php bin/console doctrine:fixtures:load --group=initial
 */
class InitialAdminFixture extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $email    = $_ENV['INITIAL_ADMIN_EMAIL']    ?? 'admin@example.com';
        $password = $_ENV['INITIAL_ADMIN_PASSWORD'] ?? 'ChangeMe123!';
        $name     = $_ENV['INITIAL_ADMIN_NAME']     ?? 'UbuntuNet Super Admin';

        // Idempotent: skip if already exists
        $existing = $manager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            echo "  [skip] Admin user already exists: {$email}\n";
            return;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFullName($name);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $manager->persist($user);
        $manager->flush();

        echo "  [ok] Created super-admin: {$email}\n";
        echo "  [!!] Change the password immediately after first login!\n";
    }
}
