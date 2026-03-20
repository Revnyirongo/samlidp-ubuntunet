<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\ApplicationVersion;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestIdSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApplicationVersion $applicationVersion,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = trim((string) $request->headers->get('X-Request-Id'));
        if ($requestId === '') {
            $requestId = strtoupper(bin2hex(random_bytes(6)));
        }

        $request->attributes->set('_request_id', $requestId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = (string) $event->getRequest()->attributes->get('_request_id', '');
        if ($requestId !== '') {
            $event->getResponse()->headers->set('X-Request-Id', $requestId);
        }

        $event->getResponse()->headers->set('X-App-Version', $this->applicationVersion->getVersion());
    }
}
