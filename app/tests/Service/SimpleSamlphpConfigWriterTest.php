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
            new TenantMetadataProfileBuilder('idp.ubuntunet.net'),
            '/tmp/config',
            '/tmp/metadata',
            '/tmp/cert',
            'idp.ubuntunet.net',
        );

        $tenant = (new Tenant())
            ->setSlug('uon')
            ->setName('University of Nairobi')
            ->setEntityId('https://uon.idp.ubuntunet.net/saml2/idp/metadata.php')
            ->setStatus(Tenant::STATUS_ACTIVE)
            ->setOrganizationName('University of Nairobi')
            ->setOrganizationUrl('https://uonbi.ac.ke')
            ->setLogoUrl('https://uonbi.ac.ke/logo.png')
            ->setTechnicalContactName('Operations Team')
            ->setTechnicalContactEmail('ops@uonbi.ac.ke')
            ->setPublishedFederations(['edugain'])
            ->setMetadataProfile([
                'description' => 'Federated identity provider for the university',
                'privacy_statement_url' => 'https://uonbi.ac.ke/privacy',
                'support_contact_name' => 'Support Desk',
                'support_contact_email' => 'support@uonbi.ac.ke',
                'security_contact_name' => 'CSIRT',
                'security_contact_email' => 'security@uonbi.ac.ke',
                'domain_hints' => ['uonbi.ac.ke'],
                'scopes' => ['uonbi.ac.ke'],
                'registration_authority' => 'https://idp.ubuntunet.net',
                'registration_policy_url' => 'https://idp.ubuntunet.net/federation/metadata-registration-practice-statement',
            ]);

        $method = new \ReflectionMethod($writer, 'buildIdpHostedBlock');
        $method->setAccessible(true);
        $block = (string) $method->invoke($writer, $tenant);

        $this->assertStringContainsString("'RegistrationInfo' => [", $block);
        $this->assertStringContainsString("'authority' => 'https://idp.ubuntunet.net'", $block);
        $this->assertStringContainsString("'policies' => ['en' => 'https://idp.ubuntunet.net/federation/metadata-registration-practice-statement']", $block);
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
