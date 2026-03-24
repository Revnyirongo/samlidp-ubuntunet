<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, LegacyPasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legacySalt = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $fullName = '';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Tenants this user can manage */
    #[ORM\ManyToMany(targetEntity: Tenant::class, mappedBy: 'admins')]
    private Collection $managedTenants;

    /** TOTP secret for 2FA */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column]
    private bool $totpEnabled = false;

    public function __construct()
    {
        $this->managedTenants = new ArrayCollection();
    }

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

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        $roles   = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function isSuperAdmin(): bool { return in_array('ROLE_SUPER_ADMIN', $this->roles, true); }
    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true) || $this->isSuperAdmin(); }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    public function getSalt(): ?string { return $this->legacySalt; }
    public function setLegacySalt(?string $salt): static { $this->legacySalt = $salt; return $this; }

    public function getFullName(): string { return $this->fullName; }
    public function setFullName(string $n): static { $this->fullName = $n; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $t): static { $this->lastLoginAt = $t; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Tenant> */
    public function getManagedTenants(): Collection { return $this->managedTenants; }

    public function canManageTenant(Tenant $tenant): bool
    {
        return $this->isSuperAdmin() || $this->managedTenants->contains($tenant);
    }

    public function getTotpSecret(): ?string { return $this->totpSecret; }
    public function setTotpSecret(?string $s): static { $this->totpSecret = $s; return $this; }
    public function isTotpEnabled(): bool { return $this->totpEnabled; }
    public function setTotpEnabled(bool $e): static { $this->totpEnabled = $e; return $this; }

    public function eraseCredentials(): void {}

    public function __toString(): string { return $this->fullName . ' <' . $this->email . '>'; }
}
