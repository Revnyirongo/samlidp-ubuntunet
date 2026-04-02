<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\EventSubscriber\AdminTwoFactorSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface  $urlGenerator,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->getString('_username');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, function (string $identifier) {
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $identifier]);

                if ($user === null) {
                    throw new CustomUserMessageAuthenticationException('Invalid email or password.');
                }
                if (!$user->isActive()) {
                    throw new CustomUserMessageAuthenticationException('Your account has been disabled.');
                }

                return $user;
            }),
            new PasswordCredentials($request->request->getString('_password')),
            [
                new CsrfTokenBadge('authenticate', $request->request->getString('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if ($user instanceof User) {
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        $session = $request->getSession();

        if ($user instanceof User && $user->isTotpEnabled() && $user->getId() !== null) {
            $targetPath = $this->getTargetPath($session, $firewallName);
            if (is_string($targetPath) && $targetPath !== '') {
                $session->set(AdminTwoFactorSubscriber::SESSION_TARGET_PATH, $targetPath);
            }

            $session->set(AdminTwoFactorSubscriber::SESSION_PENDING_USER_ID, (string) $user->getId());
            $session->remove(AdminTwoFactorSubscriber::SESSION_VERIFIED_USER_ID);

            return new RedirectResponse($this->urlGenerator->generate('app_admin_2fa_challenge'));
        }

        $session->remove(AdminTwoFactorSubscriber::SESSION_PENDING_USER_ID);
        $session->remove(AdminTwoFactorSubscriber::SESSION_VERIFIED_USER_ID);
        $session->remove(AdminTwoFactorSubscriber::SESSION_TARGET_PATH);

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }
}
