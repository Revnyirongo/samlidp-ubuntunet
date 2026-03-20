<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create or update an admin portal user.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface          $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email',    null, InputOption::VALUE_REQUIRED, 'User email address')
            ->addOption('name',     null, InputOption::VALUE_REQUIRED, 'Full name', 'Admin User')
            ->addOption('role',     null, InputOption::VALUE_REQUIRED, 'Role: ROLE_ADMIN or ROLE_SUPER_ADMIN', 'ROLE_ADMIN')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Password (prompted if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email');
        if (!$email) {
            $email = $io->ask('Email address');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("Invalid email: {$email}");
            return Command::FAILURE;
        }

        $password = $input->getOption('password');
        if (!$password) {
            $password = $io->askHidden('Password (min 12 chars)');
            $confirm  = $io->askHidden('Confirm password');
            if ($password !== $confirm) {
                $io->error('Passwords do not match.');
                return Command::FAILURE;
            }
        }
        if (strlen($password) < 12) {
            $io->error('Password must be at least 12 characters.');
            return Command::FAILURE;
        }

        $role = strtoupper($input->getOption('role'));
        if (!in_array($role, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true)) {
            $io->error("Invalid role: {$role}. Use ROLE_ADMIN or ROLE_SUPER_ADMIN.");
            return Command::FAILURE;
        }

        // Upsert
        $userRepo = $this->em->getRepository(User::class);
        $user     = $userRepo->findOneBy(['email' => $email]);
        $isNew    = false;

        if ($user === null) {
            $user  = new User();
            $isNew = true;
        }

        $user->setEmail($email);
        $user->setFullName($input->getOption('name'));
        $user->setRoles([$role]);
        $user->setIsActive(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error($error->getPropertyPath() . ': ' . $error->getMessage());
            }
            return Command::FAILURE;
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf(
            '%s user %s with role %s.',
            $isNew ? 'Created' : 'Updated',
            $email,
            $role
        ));

        return Command::SUCCESS;
    }
}
