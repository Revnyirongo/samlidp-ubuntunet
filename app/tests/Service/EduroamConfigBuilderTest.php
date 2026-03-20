<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Service\EduroamConfigBuilder;
use PHPUnit\Framework\TestCase;

final class EduroamConfigBuilderTest extends TestCase
{
    public function testDatabaseTenantBuildsSqlBundle(): void
    {
        $tenant = (new Tenant())
            ->setSlug('uon')
            ->setName('University of Nairobi')
            ->setAuthType(Tenant::AUTH_DATABASE)
            ->setOrganizationUrl('https://uonbi.ac.ke')
            ->setEduroamProfile([
                'realm' => 'uonbi.ac.ke',
                'default_eap_method' => 'peap',
            ]);

        $bundle = (new EduroamConfigBuilder())->build($tenant);

        $this->assertSame('uonbi.ac.ke', $bundle['realm']);
        $this->assertSame('database', $bundle['backendMode']);
        $this->assertTrue($bundle['supported']);
        $this->assertArrayHasKey('schema_postgresql', $bundle['files']);
        $this->assertStringContainsString('nt_password_hash', $bundle['files']['backend']['content']);
        $this->assertStringContainsString('radcheck_uon', $bundle['files']['schema_postgresql']['content']);
    }

    public function testSamlTenantIsFlaggedAsUnsupported(): void
    {
        $tenant = (new Tenant())
            ->setSlug('proxy')
            ->setName('Proxy Tenant')
            ->setAuthType(Tenant::AUTH_SAML);

        $bundle = (new EduroamConfigBuilder())->build($tenant);

        $this->assertSame('unsupported', $bundle['backendMode']);
        $this->assertFalse($bundle['supported']);
        $this->assertStringContainsString('not suitable', strtolower(implode(' ', $bundle['warnings'])));
    }
}
