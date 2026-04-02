<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AdminTwoFactorSubscriber implements EventSubscriberInterface
{
    public const SESSION_PENDING_USER_ID = 'admin_2fa_user_id';
    public const SESSION_VERIFIED_USER_ID = 'admin_2fa_verified_user_id';
    public const SESSION_TARGET_PATH = 'admin_2fa_target_path';
    public const SESSION_SETUP_SECRET = 'admin_2fa_setup_secret';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isTotpEnabled() || $user->getId() === null) {
            return;
        }

        $session = $request->getSession();
        $userId = (string) $user->getId();
        $pendingUserId = (string) $session->get(self::SESSION_PENDING_USER_ID, '');
        $verifiedUserId = (string) $session->get(self::SESSION_VERIFIED_USER_ID, '');

        if ($pendingUserId !== $userId || $verifiedUserId === $userId) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');
        if (in_array($route, ['app_admin_2fa_challenge', 'app_logout'], true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_admin_2fa_challenge')));
    }
}
