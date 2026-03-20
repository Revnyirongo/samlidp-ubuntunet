<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantUserRegistrationRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TenantUserRegistrationRequestRepository::class)]
#[ORM\Table(name: 'tenant_user_registration_requests')]
#[ORM\HasLifecycleCallbacks]
class TenantUserRegistrationRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $fullName = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9._@-]+$/',
        message: 'Username may contain letters, numbers, dots, hyphens, underscores, and @.',
    )]
    private string $username = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $givenName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $surname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $affiliation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewNotes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

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
    public function setTenant(Tenant $tenant): static { $this->tenant = $tenant; return $this; }
    public function getFullName(): string { return $this->fullName; }
    public function setFullName(string $fullName): static { $this->fullName = trim($fullName); return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = strtolower(trim($email)); return $this; }
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): static { $this->username = trim($username); return $this; }
    public function getGivenName(): ?string { return $this->givenName; }
    public function setGivenName(?string $givenName): static { $this->givenName = $this->normalizeNullable($givenName); return $this; }
    public function getSurname(): ?string { return $this->surname; }
    public function setSurname(?string $surname): static { $this->surname = $this->normalizeNullable($surname); return $this; }
    public function getAffiliation(): ?string { return $this->affiliation; }
    public function setAffiliation(?string $affiliation): static { $this->affiliation = $this->normalizeNullable($affiliation); return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): static { $this->message = $this->normalizeNullable($message); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getReviewNotes(): ?string { return $this->reviewNotes; }
    public function setReviewNotes(?string $reviewNotes): static { $this->reviewNotes = $this->normalizeNullable($reviewNotes); return $this; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static { $this->reviewedAt = $reviewedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function normalizeNullable(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
