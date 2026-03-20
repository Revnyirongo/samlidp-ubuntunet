<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private mixed $userId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private mixed $tenantId = null;

    #[ORM\Column(length: 100)]
    private string $action = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string  $action,
        mixed   $userId    = null,
        mixed   $tenantId  = null,
        ?string $entityType = null,
        ?string $entityId   = null,
        ?array  $data       = null,
        ?string $ipAddress  = null,
        ?string $userAgent  = null,
    ) {
        $this->action     = $action;
        $this->userId     = $userId;
        $this->tenantId   = $tenantId;
        $this->entityType = $entityType;
        $this->entityId   = $entityId;
        $this->data       = $data;
        $this->ipAddress  = $ipAddress;
        $this->userAgent  = $userAgent;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAction(): string { return $this->action; }
    public function getUserId(): mixed { return $this->userId; }
    public function getTenantId(): mixed { return $this->tenantId; }
    public function getEntityType(): ?string { return $this->entityType; }
    public function getEntityId(): ?string { return $this->entityId; }
    public function getData(): ?array { return $this->data; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
