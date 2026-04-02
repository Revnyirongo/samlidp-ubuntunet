<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\EventSubscriber\AdminTwoFactorSubscriber;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MfaController extends AbstractController
{
    public function __construct(
        private readonly TotpService $totpService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/profile/mfa', name: 'app_profile_mfa', methods: ['GET', 'POST'])]
    public function profileMfa(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $session = $request->getSession();
        $setupSecret = $session->get(AdminTwoFactorSubscriber::SESSION_SETUP_SECRET);

        if (!$user->isTotpEnabled() && (!is_string($setupSecret) || $setupSecret === '')) {
            $setupSecret = $this->totpService->generateSecret();
            $session->set(AdminTwoFactorSubscriber::SESSION_SETUP_SECRET, $setupSecret);
        }

        if ($request->isMethod('POST') && !$user->isTotpEnabled()) {
            if (!$this->isCsrfTokenValid('admin_mfa_setup', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $code = $request->request->getString('code');
            if (!is_string($setupSecret) || $setupSecret === '') {
                $this->addFlash('warning', 'Start setup again to create a new authenticator secret.');
                return $this->redirectToRoute('app_profile_mfa');
            }

            if (!$this->totpService->verifyCode($setupSecret, $code)) {
                $this->addFlash('danger', 'The verification code was not accepted.');
            } else {
                $user->setTotpSecret($setupSecret);
                $user->setTotpEnabled(true);
                $this->entityManager->flush();
                $session->remove(AdminTwoFactorSubscriber::SESSION_SETUP_SECRET);

                $this->addFlash('success', 'Authenticator-based sign-in verification is now enabled.');

                return $this->redirectToRoute('app_profile_mfa');
            }
        }

        return $this->render('security/profile_mfa.html.twig', [
            'user' => $user,
            'setup_secret' => !$user->isTotpEnabled() ? $setupSecret : null,
            'setup_uri' => !$user->isTotpEnabled() && is_string($setupSecret) && $setupSecret !== ''
                ? $this->totpService->getProvisioningUri($user->getEmail(), $setupSecret, 'eduID.africa')
                : null,
        ]);
    }

    #[Route('/profile/mfa/disable', name: 'app_profile_mfa_disable', methods: ['POST'])]
    public function disableProfileMfa(Request $request, #[CurrentUser] ?User $user): RedirectResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isCsrfTokenValid('admin_mfa_disable', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $code = $request->request->getString('code');
        if (!$user->isTotpEnabled() || !$this->totpService->verifyCode($user->getTotpSecret(), $code)) {
            $this->addFlash('danger', 'A valid authenticator code is required to disable sign-in verification.');
            return $this->redirectToRoute('app_profile_mfa');
        }

        $user->setTotpEnabled(false);
        $user->setTotpSecret(null);
        $this->entityManager->flush();
        $request->getSession()->remove(AdminTwoFactorSubscriber::SESSION_PENDING_USER_ID);
        $request->getSession()->remove(AdminTwoFactorSubscriber::SESSION_VERIFIED_USER_ID);

        $this->addFlash('success', 'Authenticator-based sign-in verification has been disabled.');

        return $this->redirectToRoute('app_profile_mfa');
    }

    #[Route('/login/2fa', name: 'app_admin_2fa_challenge', methods: ['GET', 'POST'])]
    public function loginChallenge(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User || $user->getId() === null) {
            return $this->redirectToRoute('app_login');
        }

        $session = $request->getSession();
        $userId = (string) $user->getId();
        if ((string) $session->get(AdminTwoFactorSubscriber::SESSION_PENDING_USER_ID, '') !== $userId) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_2fa_challenge', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            if ($this->totpService->verifyCode($user->getTotpSecret(), $request->request->getString('code'))) {
                $session->set(AdminTwoFactorSubscriber::SESSION_VERIFIED_USER_ID, $userId);
                $target = $session->get(AdminTwoFactorSubscriber::SESSION_TARGET_PATH);
                $session->remove(AdminTwoFactorSubscriber::SESSION_TARGET_PATH);

                return $this->redirect(is_string($target) && $target !== '' ? $target : $this->generateUrl('admin_dashboard'));
            }

            $this->addFlash('danger', 'The verification code was not accepted.');
        }

        return $this->render('security/admin_2fa_challenge.html.twig', [
            'user' => $user,
        ]);
    }
}
