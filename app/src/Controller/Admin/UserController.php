<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserActionToken;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\NotificationMailer;
use App\Service\UserActionTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository              $userRepo,
        private readonly TenantRepository           $tenantRepo,
        private readonly EntityManagerInterface     $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PaginatorInterface         $paginator,
        private readonly ValidatorInterface         $validator,
        private readonly AuditLogger                $audit,
        private readonly UserActionTokenService     $tokenService,
        private readonly NotificationMailer         $mailer,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = '';

        $qb = $this->userRepo->createQueryBuilder('u')->orderBy('u.createdAt', 'DESC');

        if ($q = $request->query->getString('q')) {
            $qb->andWhere('u.email LIKE :q OR u.fullName LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        return $this->render('admin/user/index.html.twig', [
            'pagination' => $this->paginator->paginate($qb, $request->query->getInt('page', 1), 25),
            'q'          => $q,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = $request->request->all('user');

            $user = new User();
            $user->setEmail($data['email'] ?? '');
            $user->setFullName($data['fullName'] ?? '');
            $user->setRoles([$data['role'] ?? 'ROLE_ADMIN']);
            $user->setIsActive(true);

            $plainPassword = $data['password'] ?? '';
            if (strlen($plainPassword) < 12) {
                $this->addFlash('danger', 'Password must be at least 12 characters.');
                return $this->render('admin/user/new.html.twig', ['user' => $user]);
            }

            $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

            $errors = $this->validator->validate($user);
            if (count($errors) > 0) {
                foreach ($errors as $e) {
                    $this->addFlash('danger', $e->getPropertyPath() . ': ' . $e->getMessage());
                }
                return $this->render('admin/user/new.html.twig', ['user' => $user]);
            }

            $this->em->persist($user);
            $this->em->flush();

            $this->audit->log('user.created', entityType: 'User', entityId: (string) $user->getId(),
                data: ['email' => $user->getEmail(), 'role' => $data['role'] ?? '']);

            $this->addFlash('success', "User {$user->getEmail()} created.");
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/new.html.twig', ['user' => new User()]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('user_edit_' . $user->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = $request->request->all('user');

            $user->setFullName($data['fullName'] ?? $user->getFullName());
            $user->setRoles([$data['role'] ?? 'ROLE_ADMIN']);
            $user->setIsActive((bool) ($data['isActive'] ?? true));

            if (!empty($data['password'])) {
                if (strlen($data['password']) < 12) {
                    $this->addFlash('danger', 'Password must be at least 12 characters.');
                    return $this->render('admin/user/edit.html.twig', ['user' => $user]);
                }
                $user->setPassword($this->hasher->hashPassword($user, $data['password']));
            }

            // Update tenant assignments
            if (isset($data['tenantIds'])) {
                // Remove all existing
                foreach ($user->getManagedTenants() as $t) {
                    $t->removeAdmin($user);
                }
                // Add selected
                foreach ($data['tenantIds'] as $tenantId) {
                    $tenant = $this->tenantRepo->find($tenantId);
                    if ($tenant) {
                        $tenant->addAdmin($user);
                    }
                }
            }

            $this->em->flush();
            $this->audit->log('user.updated', entityType: 'User', entityId: (string) $user->getId());
            $this->addFlash('success', 'User updated.');
            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'user'    => $user,
            'tenants' => $this->tenantRepo->findAll(),
        ]);
    }

    #[Route('/{id}/toggle-active', name: 'toggle_active', methods: ['POST'])]
    public function toggleActive(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('toggle-' . $user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Cannot deactivate yourself
        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'You cannot deactivate your own account.');
            return $this->redirectToRoute('admin_user_index');
        }

        $user->setIsActive(!$user->isActive());
        $this->em->flush();

        $this->audit->log(
            $user->isActive() ? 'user.activated' : 'user.deactivated',
            entityType: 'User',
            entityId: (string) $user->getId()
        );

        $this->addFlash(
            $user->isActive() ? 'success' : 'warning',
            sprintf('User %s %s.', $user->getEmail(), $user->isActive() ? 'activated' : 'deactivated')
        );

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reset-password-' . $user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $rawToken = $this->tokenService->issue($user, UserActionToken::PURPOSE_PASSWORD_RESET);

        try {
            $this->mailer->sendPasswordReset($user, $rawToken);
        } catch (\Throwable) {
            $this->addFlash('danger', 'Password reset token was created, but the email could not be sent.');
            return $this->redirectToRoute('admin_user_index');
        }

        $this->audit->log('user.password_reset_requested', entityType: 'User', entityId: (string) $user->getId());
        $this->addFlash('success', sprintf('A password reset email has been sent to %s.', $user->getEmail()));

        return $this->redirectToRoute('admin_user_index');
    }
}
