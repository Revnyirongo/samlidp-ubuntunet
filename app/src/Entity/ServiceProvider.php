<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServiceProviderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

/**
 * A SAML Service Provider registered under a Tenant IdP.
 * Can be added manually or imported from federation metadata.
 */
#[ORM\Entity(repositoryClass: ServiceProviderRepository::class)]
#[ORM\Table(name: 'service_providers')]
#[ORM\UniqueConstraint(columns: ['tenant_id', 'entity_id'])]
#[ORM\HasLifecycleCallbacks]
class ServiceProvider
{
    public const SOURCE_MANUAL    = 'manual';
    public const SOURCE_METADATA  = 'metadata';   // imported from federation aggregate
    public const SOURCE_EDUGAIN   = 'edugain';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'serviceProviders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * SAML Entity ID of the SP
     */
    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $entityId = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * Assertion Consumer Service URL
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $acsUrl = null;

    /**
     * Single Logout Service URL
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $sloUrl = null;

    /**
     * SP public certificate (base64-encoded, for assertion encryption / signature verification)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $certificate = null;

    /**
     * The NameID format to use for this SP
     */
    #[ORM\Column(length: 255)]
    private string $nameIdFormat = 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent';

    /**
     * Whether to sign assertions for this SP
     */
    #[ORM\Column]
    private bool $signAssertions = true;

    /**
     * Whether to encrypt assertions for this SP
     */
    #[ORM\Column]
    private bool $encryptAssertions = false;

    /**
     * Attribute release overrides for this specific SP (overrides tenant policy)
     * JSON: [ "urn:oid:1.3.6.1.4.1.5923.1.1.1.7", ... ]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attributeReleaseOverride = null;

    /**
     * Attributes requested by the SP in metadata.
     * JSON: [ "mail", "eduPersonPrincipalName", ... ]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $requestedAttributes = null;

    /**
     * Whether this SP is approved to receive authentications
     */
    #[ORM\Column]
    private bool $approved = false;

    /**
     * Source: manual | metadata | edugain
     */
    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_MANUAL;

    /**
     * Raw XML metadata from the SP (stored for re-import / audit)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawMetadataXml = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * When the SP metadata was last refreshed from an upstream source
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $metadataRefreshedAt = null;

    /**
     * Expiry of SP's certificate — used for renewal alerts
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $certificateExpiresAt = null;

    /**
     * Technical contact for this SP
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail = null;

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

    // ── Getters / Setters ────────────────────────────────────

    public function getId(): ?Uuid { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $t): static { $this->tenant = $t; return $this; }

    public function getEntityId(): string { return $this->entityId; }
    public function setEntityId(string $e): static { $this->entityId = $e; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $n): static { $this->name = $n; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }

    public function getAcsUrl(): ?string { return $this->acsUrl; }
    public function setAcsUrl(?string $u): static { $this->acsUrl = $u; return $this; }

    public function getSloUrl(): ?string { return $this->sloUrl; }
    public function setSloUrl(?string $u): static { $this->sloUrl = $u; return $this; }

    public function getCertificate(): ?string { return $this->certificate; }
    public function setCertificate(?string $c): static { $this->certificate = $c; return $this; }

    public function getNameIdFormat(): string { return $this->nameIdFormat; }
    public function setNameIdFormat(string $f): static { $this->nameIdFormat = $f; return $this; }

    public function isSignAssertions(): bool { return $this->signAssertions; }
    public function setSignAssertions(bool $s): static { $this->signAssertions = $s; return $this; }

    public function isEncryptAssertions(): bool { return $this->encryptAssertions; }
    public function setEncryptAssertions(bool $e): static { $this->encryptAssertions = $e; return $this; }

    public function getAttributeReleaseOverride(): ?array { return $this->attributeReleaseOverride; }
    public function setAttributeReleaseOverride(?array $a): static { $this->attributeReleaseOverride = $a; return $this; }

    public function getRequestedAttributes(): ?array { return $this->requestedAttributes; }
    public function setRequestedAttributes(?array $a): static
    {
        if ($a === null) {
            $this->requestedAttributes = null;
            return $this;
        }

        $normalized = [];
        foreach ($a as $value) {
            $attr = trim((string) $value);
            if ($attr !== '') {
                $normalized[] = $attr;
            }
        }

        $this->requestedAttributes = array_values(array_unique($normalized));
        return $this;
    }

    public function isApproved(): bool { return $this->approved; }
    public function setApproved(bool $a): static { $this->approved = $a; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $s): static { $this->source = $s; return $this; }

    public function getRawMetadataXml(): ?string { return $this->rawMetadataXml; }
    public function setRawMetadataXml(?string $x): static { $this->rawMetadataXml = $x; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getMetadataRefreshedAt(): ?\DateTimeImmutable { return $this->metadataRefreshedAt; }
    public function setMetadataRefreshedAt(?\DateTimeImmutable $t): static { $this->metadataRefreshedAt = $t; return $this; }

    public function getCertificateExpiresAt(): ?\DateTimeImmutable { return $this->certificateExpiresAt; }
    public function setCertificateExpiresAt(?\DateTimeImmutable $t): static { $this->certificateExpiresAt = $t; return $this; }

    public function getContactEmail(): ?string { return $this->contactEmail; }
    public function setContactEmail(?string $e): static { $this->contactEmail = $e; return $this; }

    public function getDisplayName(): string
    {
        return $this->name ?? $this->entityId;
    }
}
