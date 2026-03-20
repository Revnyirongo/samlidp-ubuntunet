<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\ServiceProviderRepository;
use App\Repository\TenantRepository;
use App\Service\SimpleSamlphpConfigWriter;
use App\Service\TenantMetadataProfileBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;

final class SimpleSamlphpConfigWriterTest extends TestCase
{
    public function testHostedMetadataBlockContainsRichFederationFields(): void
    {
        $writer = new SimpleSamlphpConfigWriter(
            $this->createMock(ServiceProviderRepository::class),
            $this->createMock(TenantRepository::class),
            $this->createMock(LockFactory::class),
            new NullLogger(),
            new TenantMetadataProfileBuilder('example.com'),
            '/tmp/config',
            '/tmp/metadata',
            '/tmp/cert',
            'example.com',
        );

        $tenant = (new Tenant())
            ->setSlug('university-of-africa')
            ->setName('University of Africa')
            ->setEntityId('https://university-of-africa.example.com/saml2/idp/metadata.php')
            ->setStatus(Tenant::STATUS_ACTIVE)
            ->setOrganizationName('University of Africa')
            ->setOrganizationUrl('https://www.university.example')
            ->setLogoUrl('https://www.university.example/logo.png')
            ->setTechnicalContactName('Operations Team')
            ->setTechnicalContactEmail('ops@university.example')
            ->setPublishedFederations(['edugain'])
            ->setMetadataProfile([
                'description' => 'Federated identity provider for the university',
                'privacy_statement_url' => 'https://www.university.example/privacy',
                'support_contact_name' => 'Support Desk',
                'support_contact_email' => 'support@university.example',
                'security_contact_name' => 'CSIRT',
                'security_contact_email' => 'security@university.example',
                'domain_hints' => ['university.example'],
                'scopes' => ['university.example'],
                'registration_authority' => 'https://example.com',
                'registration_policy_url' => 'https://example.com/federation/metadata-registration-practice-statement',
            ]);

        $method = new \ReflectionMethod($writer, 'buildIdpHostedBlock');
        $method->setAccessible(true);
        $block = (string) $method->invoke($writer, $tenant);

        $this->assertStringContainsString("'RegistrationInfo' => [", $block);
        $this->assertStringContainsString("'authority' => 'https://example.com'", $block);
        $this->assertStringContainsString("'policies' => ['en' => 'https://example.com/federation/metadata-registration-practice-statement']", $block);
        $this->assertStringContainsString('metadata-registration-practice-statement', $block);
        $this->assertStringContainsString("'DiscoHints' => [", $block);
        $this->assertStringContainsString("'DomainHint' => [", $block);
        $this->assertStringContainsString("'SingleLogoutServiceBinding' => [", $block);
        $this->assertStringContainsString("'UIInfo' => [", $block);
        $this->assertStringContainsString("'Logo' => [", $block);
        $this->assertStringContainsString("'height' => 80", $block);
        $this->assertStringContainsString("'width' => 200", $block);
        $this->assertStringContainsString("'contactType' => 'other'", $block);
        $this->assertStringContainsString("'remd:contactType' => 'http://refeds.org/metadata/contactType/security'", $block);
    }
}
