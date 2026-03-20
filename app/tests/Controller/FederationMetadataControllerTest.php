<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\FederationMetadataController;
use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\TenantMetadataProfileBuilder;
use PHPUnit\Framework\TestCase;

final class FederationMetadataControllerTest extends TestCase
{
    public function testTenantMetadataContainsRegistrationInfoScopeAndSecurityContact(): void
    {
        $tenant = (new Tenant())
            ->setSlug('makerere')
            ->setName('Makerere University')
            ->setEntityId('https://makerere.example.com/saml2/idp/metadata.php')
            ->setStatus(Tenant::STATUS_ACTIVE)
            ->setOrganizationName('Makerere University')
            ->setOrganizationUrl('https://mak.ac.ug')
            ->setLogoUrl('https://mak.ac.ug/assets/logo.png')
            ->setTechnicalContactName('AAI Team')
            ->setTechnicalContactEmail('aai@mak.ac.ug')
            ->setPublishedFederations(['edugain'])
            ->setSigningCertificate(str_repeat('A', 256))
            ->setMetadataProfile([
                'support_contact_name' => 'Support Desk',
                'support_contact_email' => 'support@mak.ac.ug',
                'security_contact_name' => 'CERT',
                'security_contact_email' => 'security@mak.ac.ug',
                'privacy_statement_url' => 'https://mak.ac.ug/privacy',
                'registration_authority' => 'https://example.com',
                'registration_policy_url' => 'https://example.com/federation/metadata-registration-practice-statement',
                'domain_hints' => ['mak.ac.ug'],
                'scopes' => ['mak.ac.ug'],
            ]);

        $repo = $this->createMock(TenantRepository::class);
        $repo->method('findActiveBySlug')->with('makerere')->willReturn($tenant);

        $controller = new FederationMetadataController(
            $repo,
            new TenantMetadataProfileBuilder('example.com'),
            'example.com',
        );

        $response = $controller->tenantMetadata('makerere');
        $xml = $response->getContent();

        self::assertNotFalse($xml);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<mdrpi:RegistrationInfo', $xml);
        $this->assertStringContainsString('<shibmd:Scope regexp="false">mak.ac.ug</shibmd:Scope>', $xml);
        $this->assertStringContainsString('remd:contactType="http://refeds.org/metadata/contactType/security"', $xml);
        $this->assertStringContainsString('<mdui:DiscoHints>', $xml);
        $this->assertStringContainsString('<mdui:Logo width="200" height="80"', $xml);
    }

    public function testAggregateContainsPublicationInfoAndSevenDayValidityWindow(): void
    {
        $tenant = (new Tenant())
            ->setSlug('nust')
            ->setName('NUST')
            ->setEntityId('https://nust.example.com/saml2/idp/metadata.php')
            ->setStatus(Tenant::STATUS_ACTIVE)
            ->setTechnicalContactEmail('ops@nust.na');

        $repo = $this->createMock(TenantRepository::class);
        $repo->method('findAllActive')->willReturn([$tenant]);

        $controller = new FederationMetadataController(
            $repo,
            new TenantMetadataProfileBuilder('example.com'),
            'example.com',
        );

        $response = $controller->aggregate();
        $xml = $response->getContent();

        self::assertNotFalse($xml);
        $this->assertStringContainsString('<mdrpi:PublicationInfo', $xml);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $validUntil = $dom->documentElement?->getAttribute('validUntil');
        $this->assertNotSame('', $validUntil);

        $diff = (new \DateTimeImmutable($validUntil))->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        $this->assertGreaterThanOrEqual(5 * 24 * 3600, $diff);
        $this->assertLessThanOrEqual(28 * 24 * 3600, $diff);
    }
}
