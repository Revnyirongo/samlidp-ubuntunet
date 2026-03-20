<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Represents one institution's IdP (a "tenant").
 * Each tenant gets a subdomain: <slug>.idp.ubuntunet.net
 */
#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenants')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'This slug is already in use.')]
#[UniqueEntity(fields: ['entityId'], message: 'This Entity ID is already registered.')]
class Tenant
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public const AUTH_LDAP      = 'ldap';
    public const AUTH_SAML      = 'saml';       // IdP-proxy / hub-and-spoke
    public const AUTH_USERPASS  = 'userpass';   // Local user database
    public const AUTH_DATABASE  = 'database';   // Managed local/external SQL user store
    public const AUTH_RADIUS    = 'radius';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * URL-safe slug used for the subdomain, e.g. "uon" → uon.idp.ubuntunet.net
     */
    #[ORM\Column(length: 63, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]$/',
        message: 'Slug must be lowercase alphanumeric with hyphens (2–63 chars).'
    )]
    private string $slug = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    /**
     * SAML Entity ID for this IdP (auto-generated from slug if empty)
     */
    #[ORM\Column(length: 500, unique: true)]
    private string $entityId = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::AUTH_LDAP, self::AUTH_SAML, self::AUTH_USERPASS, self::AUTH_DATABASE, self::AUTH_RADIUS])]
    private string $authType = self::AUTH_LDAP;

    // ── LDAP configuration (JSON) ────────────────────────────
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $ldapConfig = null;

    // ── SAML upstream (proxy/hub) configuration ──────────────
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $samlUpstreamConfig = null;

    // ── RADIUS configuration ──────────────────────────────────
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $radiusConfig = null;

    /**
     * Attribute release policy: which attributes to release to which SPs.
     * JSON: { "default": [...attrs...], "sp_entity_id": [...attrs...] }
     */
    #[ORM\Column(type: Types::JSON)]
    private array $attributeReleasePolicy = [];

    /**
     * Registered Service Providers for this tenant.
     */
    #[ORM\OneToMany(targetEntity: ServiceProvider::class, mappedBy: 'tenant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $serviceProviders;

    /**
     * Local users (used only when authType = userpass)
     */
    #[ORM\OneToMany(targetEntity: IdpUser::class, mappedBy: 'tenant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $users;

    /**
     * Administrators of this tenant.
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'managedTenants')]
    #[ORM\JoinTable(name: 'tenant_admins')]
    private Collection $admins;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $technicalContactEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $technicalContactName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organizationName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organizationUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoUrl = null;

    /**
     * Additional federation metadata profile settings.
     * Keys include privacy/information URLs, support & security contacts,
     * registration authority/policy, discovery hints, and extra scopes.
     */
    #[ORM\Column(type: Types::JSON)]
    private array $metadataProfile = [];

    /**
     * Tenant-specific eduroam onboarding settings used to generate
     * FreeRADIUS starter configs and recommended realm handling.
     */
    #[ORM\Column(type: Types::JSON)]
    private array $eduroamProfile = [];

    /**
     * Metadata aggregate URL(s) this tenant's SPs come from (federation metadata).
     */
    #[ORM\Column(type: Types::JSON)]
    private array $metadataAggregateUrls = [];

    /**
     * Federations this tenant should be published into.
     * Example: ["edugain", "africaconnect"].
     */
    #[ORM\Column(type: Types::JSON)]
    private array $publishedFederations = [];

    /**
     * Per-tenant IdP X.509 certificate (base64-encoded, no headers).
     * Generated automatically if null.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signingCertificate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signingPrivateKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $metadataLastRefreshed = null;

    /**
     * MFA enforcement: none | optional | required
     */
    #[ORM\Column(length: 20)]
    private string $mfaPolicy = 'none';

    /**
     * Custom CSS/branding for the login page (per-tenant).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customLoginCss = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customLoginHtml = null;

    /**
     * Notes visible only to super-admins.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNotes = null;

    public function __construct()
    {
        $this->serviceProviders = new ArrayCollection();
        $this->users            = new ArrayCollection();
        $this->admins           = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        if (empty($this->entityId)) {
            $this->entityId = 'https://' . $this->slug . '.idp.ubuntunet.net/saml2/idp/metadata.php';
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateBackendConfiguration(ExecutionContextInterface $context): void
    {
        if ($this->authType === self::AUTH_LDAP) {
            $config = $this->ldapConfig ?? [];
            if (!is_array($config) || $config === []) {
                $context->buildViolation('LDAP configuration is required when the LDAP / AD backend is selected.')
                    ->atPath('ldapConfig')
                    ->addViolation();
                return;
            }

            if (trim((string) ($config['host'] ?? '')) === '') {
                $context->buildViolation('LDAP configuration must include "host".')
                    ->atPath('ldapConfig')
                    ->addViolation();
            }

            if (trim((string) ($config['base_dn'] ?? '')) === '') {
                $context->buildViolation('LDAP configuration must include "base_dn".')
                    ->atPath('ldapConfig')
                    ->addViolation();
            }
        }

        if ($this->authType === self::AUTH_SAML) {
            $config = $this->samlUpstreamConfig ?? [];
            if (!is_array($config) || $config === []) {
                $context->buildViolation('SAML proxy configuration is required when the SAML Proxy backend is selected.')
                    ->atPath('samlUpstreamConfig')
                    ->addViolation();
                return;
            }

            $metadataUrl = trim((string) ($config['metadata_url'] ?? ''));
            $idpEntityId = trim((string) ($config['idp_entity_id'] ?? ''));

            if ($metadataUrl === '' && $idpEntityId === '') {
                $context->buildViolation('Provide at least "metadata_url" or "idp_entity_id" for the upstream SAML IdP.')
                    ->atPath('samlUpstreamConfig')
                    ->addViolation();
            }
        }

        if ($this->authType === self::AUTH_RADIUS) {
            $config = $this->radiusConfig ?? [];
            if (!is_array($config) || $config === []) {
                $context->buildViolation('RADIUS configuration is required when the RADIUS backend is selected.')
                    ->atPath('radiusConfig')
                    ->addViolation();
                return;
            }

            if (trim((string) ($config['host'] ?? '')) === '') {
                $context->buildViolation('RADIUS configuration must include "host".')
                    ->atPath('radiusConfig')
                    ->addViolation();
            }

            if (trim((string) ($config['secret'] ?? '')) === '') {
                $context->buildViolation('RADIUS configuration must include "secret".')
                    ->atPath('radiusConfig')
                    ->addViolation();
            }
        }
    }

    // ── Getters / Setters ────────────────────────────────────

    public function getId(): ?Uuid { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = strtolower(trim($slug)); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getEntityId(): string { return $this->entityId; }
    public function setEntityId(string $entityId): static { $this->entityId = $entityId; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    public function getAuthType(): string { return $this->authType; }
    public function setAuthType(string $authType): static { $this->authType = $authType; return $this; }
    public function usesDatabaseAuth(): bool
    {
        return in_array($this->authType, [self::AUTH_USERPASS, self::AUTH_DATABASE], true);
    }

    public function getLdapConfig(): ?array { return $this->ldapConfig; }
    public function setLdapConfig(?array $c): static { $this->ldapConfig = $c; return $this; }

    public function getSamlUpstreamConfig(): ?array { return $this->samlUpstreamConfig; }
    public function setSamlUpstreamConfig(?array $c): static { $this->samlUpstreamConfig = $c; return $this; }

    public function getRadiusConfig(): ?array { return $this->radiusConfig; }
    public function setRadiusConfig(?array $c): static { $this->radiusConfig = $c; return $this; }

    public function getAttributeReleasePolicy(): array { return $this->attributeReleasePolicy; }
    public function setAttributeReleasePolicy(array $p): static { $this->attributeReleasePolicy = $p; return $this; }

    /** @return Collection<int, ServiceProvider> */
    public function getServiceProviders(): Collection { return $this->serviceProviders; }

    public function addServiceProvider(ServiceProvider $sp): static
    {
        if (!$this->serviceProviders->contains($sp)) {
            $this->serviceProviders->add($sp);
            $sp->setTenant($this);
        }
        return $this;
    }

    /** @return Collection<int, IdpUser> */
    public function getUsers(): Collection { return $this->users; }

    /** @return Collection<int, User> */
    public function getAdmins(): Collection { return $this->admins; }

    public function addAdmin(User $user): static
    {
        if (!$this->admins->contains($user)) {
            $this->admins->add($user);
        }
        return $this;
    }

    public function removeAdmin(User $user): static
    {
        $this->admins->removeElement($user);
        return $this;
    }

    public function getTechnicalContactEmail(): ?string { return $this->technicalContactEmail; }
    public function setTechnicalContactEmail(?string $e): static { $this->technicalContactEmail = $e; return $this; }

    public function getTechnicalContactName(): ?string { return $this->technicalContactName; }
    public function setTechnicalContactName(?string $n): static { $this->technicalContactName = $n; return $this; }

    public function getOrganizationName(): ?string { return $this->organizationName; }
    public function setOrganizationName(?string $n): static { $this->organizationName = $n; return $this; }

    public function getOrganizationUrl(): ?string { return $this->organizationUrl; }
    public function setOrganizationUrl(?string $u): static { $this->organizationUrl = $u; return $this; }

    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function setLogoUrl(?string $u): static { $this->logoUrl = $u; return $this; }

    public function getMetadataProfile(): array { return $this->metadataProfile; }
    public function setMetadataProfile(array $profile): static
    {
        $this->metadataProfile = $profile;
        return $this;
    }

    public function getEduroamProfile(): array { return $this->eduroamProfile; }
    public function setEduroamProfile(array $profile): static
    {
        $this->eduroamProfile = $profile;
        return $this;
    }

    public function getMetadataAggregateUrls(): array { return $this->metadataAggregateUrls; }
    public function setMetadataAggregateUrls(array $u): static { $this->metadataAggregateUrls = $u; return $this; }

    public function getPublishedFederations(): array { return $this->publishedFederations; }
    public function setPublishedFederations(array $f): static
    {
        $normalized = [];
        foreach ($f as $value) {
            $slug = strtolower(trim((string) $value));
            if ($slug === '') {
                continue;
            }

            $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?? '';
            $slug = trim($slug, '-');
            if ($slug !== '') {
                $normalized[] = $slug;
            }
        }

        $this->publishedFederations = array_values(array_unique($normalized));
        return $this;
    }

    public function getSigningCertificate(): ?string { return $this->signingCertificate; }
    public function setSigningCertificate(?string $c): static { $this->signingCertificate = $c; return $this; }

    public function getSigningPrivateKey(): ?string { return $this->signingPrivateKey; }
    public function setSigningPrivateKey(?string $k): static { $this->signingPrivateKey = $k; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getMetadataLastRefreshed(): ?\DateTimeImmutable { return $this->metadataLastRefreshed; }
    public function setMetadataLastRefreshed(?\DateTimeImmutable $t): static { $this->metadataLastRefreshed = $t; return $this; }

    public function getMfaPolicy(): string { return $this->mfaPolicy; }
    public function setMfaPolicy(string $p): static { $this->mfaPolicy = $p; return $this; }

    public function getCustomLoginCss(): ?string { return $this->customLoginCss; }
    public function setCustomLoginCss(?string $c): static { $this->customLoginCss = $c; return $this; }

    public function getCustomLoginHtml(): ?string { return $this->customLoginHtml; }
    public function setCustomLoginHtml(?string $h): static { $this->customLoginHtml = $h; return $this; }

    public function getAdminNotes(): ?string { return $this->adminNotes; }
    public function setAdminNotes(?string $n): static { $this->adminNotes = $n; return $this; }

    public function getIdpUrl(): string
    {
        return 'https://' . $this->slug . '.idp.ubuntunet.net';
    }

    public function getSsoUrl(): string
    {
        return $this->getIdpUrl() . '/saml2/idp/SSOService.php';
    }

    public function getSloUrl(): string
    {
        return $this->getIdpUrl() . '/saml2/idp/SingleLogoutService.php';
    }

    public function getMetadataUrl(): string
    {
        return $this->getIdpUrl() . '/saml2/idp/metadata.php';
    }

    public function __toString(): string
    {
        return $this->name . ' (' . $this->slug . ')';
    }
}
