<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Writes structured audit log entries to the database.
 * Inject and call $auditLogger->log(...) from controllers/services.
 */
class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack           $requestStack,
        private readonly TokenStorageInterface  $tokenStorage,
    ) {}

    public function log(
        string  $action,
        mixed   $tenantId   = null,
        ?string $entityType = null,
        ?string $entityId   = null,
        ?array  $data       = null,
    ): void {
        $request   = $this->requestStack->getCurrentRequest();
        $token     = $this->tokenStorage->getToken();
        $user      = $token?->getUser();

        $entry = new AuditLog(
            action:     $action,
            userId:     $user?->getId(),
            tenantId:   $tenantId,
            entityType: $entityType,
            entityId:   $entityId,
            data:       $data,
            ipAddress:  $request?->getClientIp(),
            userAgent:  $request?->headers->get('User-Agent'),
        );

        $this->em->persist($entry);
        $this->em->flush();
    }
}
