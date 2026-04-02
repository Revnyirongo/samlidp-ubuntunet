<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RegistrationRequest;
use App\Entity\User;
use App\Entity\UserActionToken;
use App\Repository\RegistrationRequestRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\NotificationMailer;
use App\Service\PublicFormProtection;
use App\Service\UserActionTokenService;
use App\Service\MailerStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AccountController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly TenantRepository $tenantRepo,
        private readonly RegistrationRequestRepository $registrationRepo,
        private readonly EntityManagerInterface $em,
        private readonly UserActionTokenService $tokenService,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly NotificationMailer $mailer,
        private readonly MailerStatus $mailerStatus,
        private readonly PublicFormProtection $formProtection,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $tenants = $this->tenantRepo->findAllActive();
        $data = [
            'fullName' => '',
            'email' => '',
            'organizationName' => '',
            'tenantId' => '',
            'message' => '',
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('self_register', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = array_replace($data, $request->request->all('registration'));
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $formError = $this->formProtection->validateSubmission($request, PublicFormProtection::PUBLIC_REGISTER);

            if ($formError !== null) {
                $this->addFlash('danger', $formError);
            } elseif ($email === '' || trim((string) ($data['fullName'] ?? '')) === '') {
                $this->addFlash('danger', 'Full name and email are required.');
            } elseif ($this->registrationRepo->findPendingByEmail($email) !== null) {
                $this->addFlash('info', 'A registration request for this email is already pending review.');
            } else {
                $registration = (new RegistrationRequest())
                    ->setFullName(trim((string) $data['fullName']))
                    ->setEmail($email)
                    ->setOrganizationName(trim((string) ($data['organizationName'] ?? '')) ?: null)
                    ->setMessage(trim((string) ($data['message'] ?? '')) ?: null);

                $tenantId = trim((string) ($data['tenantId'] ?? ''));
                if ($tenantId !== '') {
                    $tenant = $this->tenantRepo->find($tenantId);
                    if ($tenant !== null && $tenant->isActive()) {
                        $registration->setRequestedTenant($tenant);
                    }
                }

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
                        $this->mailer->sendRegistrationReceived($registration);
                        foreach ($this->registrationReviewRecipients($registration) as $recipient) {
                            $this->mailer->sendRegistrationReviewNotification($recipient, $registration);
                        }
                    } catch (\Throwable) {
                        $mailFailed = true;
                    }

                    if (!$this->mailerStatus->isEnabled()) {
                        $this->addFlash('warning', 'Registration request submitted, but email delivery is currently disabled on this server. No notification emails were sent.');
                    } elseif ($mailFailed) {
                        $this->addFlash('warning', 'Registration request submitted, but notification email delivery failed. The request is still queued for review.');
                    } else {
                        $this->addFlash('success', 'Registration request submitted. You will receive an email when it is reviewed.');
                    }
                    return $this->redirectToRoute('app_login');
                }
            }
        }

        return $this->render('security/register.html.twig', [
            'tenants' => $tenants,
            'formData' => $data,
            'form_challenge' => $this->formProtection->issueChallenge($request, PublicFormProtection::PUBLIC_REGISTER),
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
        ]);
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $formError = $this->formProtection->validateSubmission($request, PublicFormProtection::PUBLIC_FORGOT_PASSWORD);
            if ($formError !== null) {
                $this->addFlash('danger', $formError);
            } else {
                $email = strtolower(trim($request->request->getString('email')));
                $user = $this->userRepo->findOneBy(['email' => $email]);

                if ($user instanceof User) {
                    $mailFailed = false;
                    try {
                        $rawToken = $this->tokenService->issue($user, UserActionToken::PURPOSE_PASSWORD_RESET);
                        $this->mailer->sendPasswordReset($user, $rawToken);
                    } catch (\Throwable) {
                        $mailFailed = true;
                    }
                } else {
                    $mailFailed = false;
                }

                if (!$this->mailerStatus->isEnabled()) {
                    $this->addFlash('warning', 'Mail delivery is currently disabled on this server. No password reset email was sent.');
                } elseif ($mailFailed ?? false) {
                    $this->addFlash('warning', 'If that account exists, the reset request was recorded but email delivery failed. Contact support.');
                } else {
                    $this->addFlash('success', 'If that account exists, a password reset email has been sent.');
                }
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/forgot_password.html.twig', [
            'form_challenge' => $this->formProtection->issueChallenge($request, PublicFormProtection::PUBLIC_FORGOT_PASSWORD),
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(string $token, Request $request): Response
    {
        $actionToken = $this->tokenService->findValid($token, UserActionToken::PURPOSE_PASSWORD_RESET)
            ?? $this->tokenService->findValid($token, UserActionToken::PURPOSE_SET_PASSWORD);

        if ($actionToken === null) {
            $this->addFlash('danger', 'That password link is invalid or has expired.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $password = $request->request->getString('password');
            $confirm = $request->request->getString('password_confirm');

            if (strlen($password) < 12) {
                $this->addFlash('danger', 'Password must be at least 12 characters.');
            } elseif ($password !== $confirm) {
                $this->addFlash('danger', 'Password confirmation does not match.');
            } else {
                $user = $actionToken->getUser();
                if (!$user instanceof User) {
                    $this->addFlash('danger', 'That password link is invalid.');
                    return $this->redirectToRoute('app_forgot_password');
                }

                $user->setPassword($this->hasher->hashPassword($user, $password));
                $actionToken->setUsedAt(new \DateTimeImmutable());
                $this->em->flush();

                $mailFailed = false;
                try {
                    $this->mailer->sendPasswordChangedConfirmation($user);
                } catch (\Throwable) {
                    $mailFailed = true;
                }

                $this->addFlash($mailFailed ? 'warning' : 'success', $mailFailed
                    ? 'Password updated, but the confirmation email could not be sent.'
                    : 'Password updated. You can sign in now.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
            'mode' => $actionToken->getPurpose() === UserActionToken::PURPOSE_SET_PASSWORD ? 'set' : 'reset',
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function registrationReviewRecipients(RegistrationRequest $request): array
    {
        $recipients = [];

        $tenantEmail = $request->getRequestedTenant()?->getTechnicalContactEmail();
        if (is_string($tenantEmail) && $tenantEmail !== '') {
            $recipients[] = strtolower($tenantEmail);
        }

        if ($request->getRequestedTenant() !== null) {
            foreach ($request->getRequestedTenant()->getAdmins() as $admin) {
                $recipients[] = strtolower($admin->getEmail());
            }
        }

        foreach ($this->userRepo->findAll() as $user) {
            if ($user->isSuperAdmin()) {
                $recipients[] = strtolower($user->getEmail());
            }
        }

        return array_values(array_unique(array_filter($recipients)));
    }
}
