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
use App\Service\PublicFormProtection;
use App\Service\TenantLocalCredentialService;
use App\Service\TotpService;
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
        private readonly PublicFormProtection $formProtection,
        private readonly TenantLocalCredentialService $tenantLocalCredentialService,
        private readonly TotpService $totpService,
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
            $formError = $this->formProtection->validateSubmission(
                $request,
                PublicFormProtection::TENANT_REGISTER,
                $tenant->getSlug()
            );

            if ($formError !== null) {
                $this->addFlash('danger', $formError);
            } elseif ($fullName === '' || $username === '' || $email === '') {
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
            'form_challenge' => $this->formProtection->issueChallenge($request, PublicFormProtection::TENANT_REGISTER),
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

            $formError = $this->formProtection->validateSubmission(
                $request,
                PublicFormProtection::TENANT_FORGOT_PASSWORD,
                $tenant->getSlug()
            );
            if ($formError !== null) {
                $this->addFlash('danger', $formError);
            } else {
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
        }

        return $this->render('security/tenant_forgot_password.html.twig', [
            'tenant' => $tenant,
            'form_challenge' => $this->formProtection->issueChallenge($request, PublicFormProtection::TENANT_FORGOT_PASSWORD),
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

    #[Route('/tenant/{slug}/mfa/setup', name: 'app_tenant_mfa_setup', methods: ['GET', 'POST'])]
    public function setupMfa(string $slug, Request $request): Response
    {
        $tenant = $this->tenantRepo->findActiveBySlug($slug);
        if ($tenant === null || !$tenant->usesDatabaseAuth()) {
            throw $this->createNotFoundException('Tenant not found.');
        }

        $sessionKey = 'tenant_mfa_setup_' . $tenant->getSlug();
        $session = $request->getSession();
        $pending = $session->get($sessionKey);

        if (!is_array($pending)) {
            $pending = null;
        }

        $formData = [
            'identifier' => '',
            'password' => '',
        ];

        if ($request->isMethod('POST')) {
            $stage = $request->request->getString('stage', $pending === null ? 'identify' : 'confirm');

            if ($stage === 'identify') {
                if (!$this->isCsrfTokenValid('tenant_mfa_setup_identify_' . $tenant->getSlug(), $request->request->getString('_token'))) {
                    throw $this->createAccessDeniedException('Invalid CSRF token.');
                }

                $formData['identifier'] = trim($request->request->getString('identifier'));
                $formData['password'] = $request->request->getString('password');
                $user = $this->tenantLocalCredentialService->findByIdentifier($tenant, $formData['identifier']);

                if (!$user instanceof IdpUser || !$user->isActive() || !$this->tenantLocalCredentialService->verifyPassword($user, $formData['password'])) {
                    $this->addFlash('danger', 'The supplied username/email and password were not accepted.');
                } elseif ($user->isTotpEnabled()) {
                    $this->addFlash('info', 'An authenticator app is already configured for this account.');
                    return $this->redirectToRoute('app_tenant_continue_to_login', ['slug' => $tenant->getSlug()]);
                } else {
                    $secret = $this->totpService->generateSecret();
                    $pending = [
                        'user_id' => (string) $user->getId(),
                        'identifier' => $formData['identifier'],
                        'secret' => $secret,
                    ];
                    $session->set($sessionKey, $pending);

                    return $this->redirectToRoute('app_tenant_mfa_setup', ['slug' => $tenant->getSlug()]);
                }
            }

            if ($stage === 'confirm') {
                if (!$this->isCsrfTokenValid('tenant_mfa_setup_confirm_' . $tenant->getSlug(), $request->request->getString('_token'))) {
                    throw $this->createAccessDeniedException('Invalid CSRF token.');
                }

                if ($pending === null) {
                    $this->addFlash('warning', 'Start the authenticator setup again.');
                    return $this->redirectToRoute('app_tenant_mfa_setup', ['slug' => $tenant->getSlug()]);
                }

                $code = $request->request->getString('code');
                if (!$this->totpService->verifyCode((string) ($pending['secret'] ?? ''), $code)) {
                    $this->addFlash('danger', 'The verification code was not accepted.');
                } else {
                    $user = $this->idpUserRepo->find($pending['user_id'] ?? null);
                    if (!$user instanceof IdpUser || $user->getTenant()?->getId() != $tenant->getId()) {
                        $session->remove($sessionKey);
                        $this->addFlash('danger', 'The authenticator setup session has expired.');
                        return $this->redirectToRoute('app_tenant_mfa_setup', ['slug' => $tenant->getSlug()]);
                    }

                    $user->setTotpSecret((string) $pending['secret']);
                    $user->setTotpEnabled(true);
                    $this->em->flush();
                    $session->remove($sessionKey);

                    $this->addFlash('success', 'Authenticator-based sign-in verification is now enabled for your account.');

                    return $this->redirectToRoute('app_tenant_continue_to_login', ['slug' => $tenant->getSlug()]);
                }
            }
        }

        return $this->render('security/tenant_mfa_setup.html.twig', [
            'tenant' => $tenant,
            'pending_setup' => $pending,
            'setup_uri' => is_array($pending)
                ? $this->totpService->getProvisioningUri((string) ($pending['identifier'] ?? $tenant->getSlug()), (string) $pending['secret'], $tenant->getName())
                : null,
            'formData' => $formData,
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
