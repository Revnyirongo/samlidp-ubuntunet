<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdpUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A local user belonging to a tenant that uses userpass auth.
 * Stored in the `idp_users` table; read directly by the ubuntunet SSP module.
 */
#[ORM\Entity(repositoryClass: IdpUserRepository::class)]
#[ORM\Table(name: 'idp_users')]
#[ORM\UniqueConstraint(columns: ['tenant_id', 'username'])]
#[ORM\HasLifecycleCallbacks]
class IdpUser
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $username = '';

    /** BCrypt/Argon2 hash */
    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legacySalt = null;

    /**
     * Uppercase NT hash used by FreeRADIUS for MSCHAPv2 when this
     * managed user store is used for eduroam authentication.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ntPasswordHash = null;

    /**
     * SAML attributes to release for this user.
     * JSON: { "mail": ["user@example.org"], "cn": ["Full Name"], ... }
     */
    #[ORM\Column(type: Types::JSON)]
    private array $attributes = [];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $t): static { $this->tenant = $t; return $this; }
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $u): static { $this->username = $u; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $p): static { $this->password = $p; return $this; }
    public function getLegacySalt(): ?string { return $this->legacySalt; }
    public function setLegacySalt(?string $salt): static { $this->legacySalt = $salt; return $this; }
    public function getNtPasswordHash(): ?string { return $this->ntPasswordHash; }
    public function setNtPasswordHash(?string $hash): static { $this->ntPasswordHash = $hash; return $this; }
    public function getAttributes(): array { return $this->attributes; }
    public function setAttributes(array $a): static { $this->attributes = $a; return $this; }
    public function getEmail(): ?string { return $this->attributeFirstValue('mail'); }
    public function getDisplayName(): ?string { return $this->attributeFirstValue('displayName') ?? $this->attributeFirstValue('cn'); }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static { $this->lastLoginAt = $lastLoginAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function attributeFirstValue(string $key): ?string
    {
        $value = $this->attributes[$key][0] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
