<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IdpUser;
use App\Entity\IdpUserActionToken;
use App\Entity\TenantUserRegistrationRequest;
use App\Repository\IdpUserRepository;
use App\Repository\TenantRepository;
use App\Repository\TenantUserRegistrationRequestRepository;
use App\Service\IdpUserActionTokenService;
use App\Service\IdpUserPasswordManager;
use App\Service\MailerStatus;
use App\Service\NotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TenantAccountController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepo,
        private readonly IdpUserRepository $idpUserRepo,
        private readonly TenantUserRegistrationRequestRepository $registrationRepo,
        private readonly IdpUserActionTokenService $tokenService,
        private readonly IdpUserPasswordManager $passwordManager,
        private readonly NotificationMailer $mailer,
        private readonly MailerStatus $mailerStatus,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/tenant/{slug}/register', name: 'app_tenant_register', methods: ['GET', 'POST'])]
    public function register(string $slug, Request $request): Response
    {
        $tenant = $this->tenantRepo->findActiveBySlug($slug);
        if ($tenant === null || !$tenant->usesDatabaseAuth()) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        $data = [
            'fullName' => '',
            'givenName' => '',
            'surname' => '',
            'username' => '',
            'email' => '',
            'affiliation' => '',
            'message' => '',
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_register_' . $tenant->getSlug(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = array_replace($data, $request->request->all('registration'));
            $username = trim((string) ($data['username'] ?? ''));
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $fullName = trim((string) ($data['fullName'] ?? ''));

            if ($fullName === '' || $username === '' || $email === '') {
                $this->addFlash('danger', 'Full name, username, and email are required.');
            } elseif ($this->idpUserRepo->findByTenantAndUsername($tenant, $username) !== null) {
                $this->addFlash('warning', 'That username is already in use for this tenant.');
            } elseif ($this->idpUserRepo->findByTenantAndEmail($tenant, $email) !== null) {
                $this->addFlash('warning', 'An account already exists for that email address. Use the password reset link instead.');
            } elseif ($this->registrationRepo->findPendingByTenantAndUsername($tenant, $username) !== null) {
                $this->addFlash('info', 'A registration request for that username is already pending review.');
            } elseif ($this->registrationRepo->findPendingByTenantAndEmail($tenant, $email) !== null) {
                $this->addFlash('info', 'A registration request for that email address is already pending review.');
            } else {
                $registration = (new TenantUserRegistrationRequest())
                    ->setTenant($tenant)
                    ->setFullName($fullName)
                    ->setGivenName((string) ($data['givenName'] ?? ''))
                    ->setSurname((string) ($data['surname'] ?? ''))
                    ->setUsername($username)
                    ->setEmail($email)
                    ->setAffiliation((string) ($data['affiliation'] ?? ''))
                    ->setMessage((string) ($data['message'] ?? ''));

                $errors = $this->validator->validate($registration);
                if (count($errors) > 0) {
                    foreach ($errors as $error) {
                        $this->addFlash('danger', $error->getPropertyPath() . ': ' . $error->getMessage());
                    }
                } else {
                    $this->em->persist($registration);
                    $this->em->flush();

                    $mailFailed = false;
                    try {
                        $this->mailer->sendTenantRegistrationReceived($registration);
                        foreach ($this->registrationReviewRecipients($tenant) as $recipient) {
                            $this->mailer->sendTenantRegistrationReviewNotification($recipient, $registration);
                        }
                    } catch (\Throwable) {
                        $mailFailed = true;
                    }

                    if (!$this->mailerStatus->isEnabled()) {
                        $this->addFlash('warning', 'Request submitted. Email notifications are currently unavailable.');
                    } elseif ($mailFailed) {
                        $this->addFlash('warning', 'Request submitted. Notification email delivery could not be completed.');
                    } else {
                        $this->addFlash('success', 'Request submitted. You will be notified after review.');
                    }

                    return $this->redirectToRoute('app_tenant_register', [
                        'slug' => $tenant->getSlug(),
                        'submitted' => 1,
                    ]);
                }
            }
        }

        return $this->render('security/tenant_register.html.twig', [
            'tenant' => $tenant,
            'formData' => $data,
            'submitted' => $request->query->getBoolean('submitted'),
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
            'auth_brand_logo' => $tenant->getLogoUrl(),
            'auth_brand_name' => $tenant->getName(),
        ]);
    }

    #[Route('/tenant/{slug}/forgot-password', name: 'app_tenant_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(string $slug, Request $request): Response
    {
        $tenant = $this->tenantRepo->findActiveBySlug($slug);
        if ($tenant === null || !$tenant->usesDatabaseAuth()) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_forgot_password_' . $tenant->getSlug(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = strtolower(trim($request->request->getString('email')));
            $user = $this->idpUserRepo->findByTenantAndEmail($tenant, $email);

            if ($user instanceof IdpUser && $user->isActive() && $user->getEmail() !== null) {
                $mailFailed = false;
                try {
                    $rawToken = $this->tokenService->issue($user, IdpUserActionToken::PURPOSE_PASSWORD_RESET);
                    $this->mailer->sendTenantUserPasswordReset($user, $rawToken);
                } catch (\Throwable) {
                    $mailFailed = true;
                }
            } else {
                $mailFailed = false;
            }

            if (!$this->mailerStatus->isEnabled()) {
                $this->addFlash('warning', 'Reset request accepted. Email delivery is currently unavailable.');
            } elseif ($mailFailed) {
                $this->addFlash('warning', 'Reset request accepted, but the email could not be delivered.');
            } else {
                $this->addFlash('success', 'If that account exists, a reset link has been sent.');
            }
            return $this->redirectToRoute('app_tenant_forgot_password', [
                'slug' => $tenant->getSlug(),
                'sent' => 1,
            ]);
        }

        return $this->render('security/tenant_forgot_password.html.twig', [
            'tenant' => $tenant,
            'sent' => $request->query->getBoolean('sent'),
            'updated' => $request->query->getBoolean('updated'),
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
            'auth_brand_logo' => $tenant->getLogoUrl(),
            'auth_brand_name' => $tenant->getName(),
        ]);
    }

    #[Route('/tenant-users/reset/{token}', name: 'app_tenant_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(string $token, Request $request): Response
    {
        $actionToken = $this->tokenService->findValid($token, IdpUserActionToken::PURPOSE_PASSWORD_RESET)
            ?? $this->tokenService->findValid($token, IdpUserActionToken::PURPOSE_SET_PASSWORD);

        if ($actionToken === null) {
            $this->addFlash('danger', 'That password link is invalid or has expired.');
            return $this->redirectToRoute('app_login');
        }

        $user = $actionToken->getUser();
        $tenant = $user?->getTenant();
        if (!$user instanceof IdpUser || $tenant === null) {
            $this->addFlash('danger', 'That password link is invalid.');
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_reset_password', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $password = $request->request->getString('password');
            $confirm = $request->request->getString('password_confirm');

            if (strlen($password) < 12) {
                $this->addFlash('danger', 'Password must be at least 12 characters.');
            } elseif ($password !== $confirm) {
                $this->addFlash('danger', 'Password confirmation does not match.');
            } else {
                $this->passwordManager->applyPassword($user, $password);
                $user->setIsActive(true);
                $actionToken->setUsedAt(new \DateTimeImmutable());
                $this->em->flush();

                $mailFailed = false;
                try {
                    $this->mailer->sendTenantUserPasswordChangedConfirmation($user);
                } catch (\Throwable) {
                    $mailFailed = true;
                }

                $this->addFlash($mailFailed ? 'warning' : 'success', $mailFailed
                    ? 'Password updated. The confirmation email could not be sent.'
                    : 'Password updated successfully.');

                return $this->redirectToRoute('app_tenant_forgot_password', [
                    'slug' => $tenant->getSlug(),
                    'updated' => 1,
                ]);
            }
        }

        return $this->render('security/tenant_reset_password.html.twig', [
            'tenant' => $tenant,
            'token' => $token,
            'mode' => $actionToken->getPurpose() === IdpUserActionToken::PURPOSE_SET_PASSWORD ? 'set' : 'reset',
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
            'auth_brand_logo' => $tenant->getLogoUrl(),
            'auth_brand_name' => $tenant->getName(),
        ]);
    }

    #[Route('/tenant/{slug}/continue-to-login', name: 'app_tenant_continue_to_login', methods: ['GET'])]
    public function continueToLogin(string $slug): RedirectResponse
    {
        $tenant = $this->tenantRepo->findActiveBySlug($slug);
        if ($tenant === null || !$tenant->usesDatabaseAuth()) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        return $this->redirect($this->tenantSimpleSamlLoginUrl($tenant->getSlug()));
    }

    /**
     * @return list<string>
     */
    private function registrationReviewRecipients(\App\Entity\Tenant $tenant): array
    {
        $recipients = [];

        $tenantEmail = $tenant->getTechnicalContactEmail();
        if (is_string($tenantEmail) && $tenantEmail !== '') {
            $recipients[] = strtolower($tenantEmail);
        }

        foreach ($tenant->getAdmins() as $admin) {
            $recipients[] = strtolower($admin->getEmail());
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    private function tenantSimpleSamlLoginUrl(string $slug): string
    {
        return sprintf('https://%s.%s/simplesaml/', $slug, $this->hostFromRequestBase());
    }

    private function hostFromRequestBase(): string
    {
        $host = (string) $this->getParameter('samlidp.hostname');

        return preg_replace('/^https?:\/\//', '', $host) ?: $host;
    }
}
