<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserActionTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserActionTokenRepository::class)]
#[ORM\Table(name: 'user_action_tokens')]
#[ORM\UniqueConstraint(columns: ['token_hash'])]
#[ORM\HasLifecycleCallbacks]
class UserActionToken
{
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const PURPOSE_SET_PASSWORD = 'set_password';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 32)]
    private string $purpose = self::PURPOSE_PASSWORD_RESET;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getPurpose(): string { return $this->purpose; }
    public function setPurpose(string $purpose): static { $this->purpose = $purpose; return $this; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $tokenHash): static { $this->tokenHash = $tokenHash; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function setUsedAt(?\DateTimeImmutable $usedAt): static { $this->usedAt = $usedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isUsable(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->usedAt === null && $this->expiresAt > $now;
    }
}
