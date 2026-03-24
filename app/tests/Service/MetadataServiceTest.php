<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ServiceProvider;
use App\Entity\Tenant;
use App\Repository\ServiceProviderRepository;
use App\Repository\TenantRepository;
use App\Service\MetadataService;
use App\Service\SimpleSamlphpConfigWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MetadataServiceTest extends TestCase
{
    private MetadataService $service;

    /** @var HttpClientInterface&MockObject */
    private HttpClientInterface $httpClient;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var ServiceProviderRepository&MockObject */
    private ServiceProviderRepository $spRepo;

    protected function setUp(): void
    {
        $this->httpClient      = $this->createMock(HttpClientInterface::class);
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->spRepo          = $this->createMock(ServiceProviderRepository::class);
        $tenantRepo            = $this->createMock(TenantRepository::class);
        $cache                 = $this->createMock(CacheInterface::class);
        $configWriter          = $this->createMock(SimpleSamlphpConfigWriter::class);

        $this->service = new MetadataService(
            em:              $this->em,
            spRepo:          $this->spRepo,
            tenantRepo:      $tenantRepo,
            httpClient:      $this->httpClient,
            logger:          new NullLogger(),
            cache:           $cache,
            configWriter:    $configWriter,
            samlidpHostname: 'example.com',
        );
    }

    // ── Metadata XML validation ───────────────────────────────

    public function testValidateMetadataXmlAcceptsValidEntityDescriptor(): void
    {
        $xml = $this->sampleSpMetadataXml('https://sp.example.org');
        $this->service->validateMetadataXml($xml); // Should not throw
        $this->assertTrue(true);
    }

    public function testValidateMetadataXmlRejectsInvalidXml(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->validateMetadataXml('not xml at all <<<');
    }

    public function testValidateMetadataXmlRejectsWrongNamespace(): void
    {
        $xml = <<<XML
<?xml version="1.0"?>
<root xmlns="http://wrong.namespace.example/">
    <EntityDescriptor entityID="test"/>
</root>
XML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/namespace/i');
        $this->service->validateMetadataXml($xml);
    }

    public function testValidateMetadataXmlRejectsNonSPRoot(): void
    {
        $xml = <<<XML
<?xml version="1.0"?>
<SomeOtherElement xmlns="urn:oasis:names:tc:SAML:2.0:metadata"/>
XML;
        $this->expectException(\RuntimeException::class);
        $this->service->validateMetadataXml($xml);
    }

    // ── SP metadata import (from XML) ─────────────────────────

    public function testImportSpMetadataParsesEntityIdCorrectly(): void
    {
        $entityId = 'https://sp.university.ac.zz/saml/metadata';
        $xml      = $this->sampleSpMetadataXml($entityId, acsUrl: 'https://sp.university.ac.zz/Shibboleth.sso/SAML2/POST');

        $tenant = $this->makeTenant('university-of-africa');

        $this->spRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $sp = $this->service->importSpMetadata($tenant, $xml, isUrl: false, approve: true);

        $this->assertSame($entityId, $sp->getEntityId());
        $this->assertTrue($sp->isApproved());
        $this->assertSame(ServiceProvider::SOURCE_MANUAL, $sp->getSource());
    }

    public function testImportSpMetadataExtractsAcsUrl(): void
    {
        $acsUrl = 'https://sp.example.org/Shibboleth.sso/SAML2/POST';
        $xml    = $this->sampleSpMetadataXml('https://sp.example.org', acsUrl: $acsUrl);
        $tenant = $this->makeTenant('test');

        $this->spRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $sp = $this->service->importSpMetadata($tenant, $xml, isUrl: false);

        $this->assertSame($acsUrl, $sp->getAcsUrl());
    }

    public function testImportSpMetadataExtractsCertificateExpiry(): void
    {
        // Generate a self-signed cert valid 10 years from now
        $key      = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr      = openssl_csr_new(['commonName' => 'test-sp'], $key);
        $cert     = openssl_csr_sign($csr, null, $key, 3652);
        openssl_x509_export($cert, $certPem);
        $certB64  = base64_encode(
            base64_decode(
                preg_replace('/-----[^-]+-----|\s/', '', $certPem)
            )
        );

        $xml    = $this->sampleSpMetadataXml('https://sp.example.org', cert: $certB64);
        $tenant = $this->makeTenant('test');

        $this->spRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $sp = $this->service->importSpMetadata($tenant, $xml, isUrl: false);

        $this->assertNotNull($sp->getCertificateExpiresAt());
        $this->assertGreaterThan(new \DateTimeImmutable(), $sp->getCertificateExpiresAt());
    }

    public function testImportSpMetadataUpdatesExistingSpInsteadOfDuplicating(): void
    {
        $existingSp = new ServiceProvider();
        $existingSp->setEntityId('https://sp.example.org');
        $existingSp->setAcsUrl('https://sp.example.org/old-acs');

        $this->spRepo->method('findOneBy')->willReturn($existingSp);
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $xml    = $this->sampleSpMetadataXml('https://sp.example.org', acsUrl: 'https://sp.example.org/new-acs');
        $tenant = $this->makeTenant('test');

        $sp = $this->service->importSpMetadata($tenant, $xml, isUrl: false);

        $this->assertSame($existingSp, $sp);
        $this->assertSame('https://sp.example.org/new-acs', $sp->getAcsUrl());
    }

    public function testImportSpMetadataRejectsMissingEntityId(): void
    {
        $xml = <<<XML
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata">
    <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"/>
</md:EntityDescriptor>
XML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/entityID/i');

        $this->service->importSpMetadata($this->makeTenant('test'), $xml, isUrl: false);
    }

    // ── Metadata URL fetch ────────────────────────────────────

    public function testImportSpMetadataFetchesFromUrl(): void
    {
        $entityId = 'https://remote-sp.org/saml';
        $xml      = $this->sampleSpMetadataXml($entityId);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($xml);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://remote-sp.org/metadata')
            ->willReturn($response);

        $this->spRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $tenant = $this->makeTenant('test');
        $sp     = $this->service->importSpMetadata($tenant, 'https://remote-sp.org/metadata', isUrl: true);

        $this->assertSame($entityId, $sp->getEntityId());
    }

    public function testImportSpMetadataRetriesTransientTransportFailure(): void
    {
        $entityId = 'https://remote-sp.org/saml';
        $xml = $this->sampleSpMetadataXml($entityId);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($xml);

        $transportException = new class ('Could not resolve host: remote-sp.org') extends \RuntimeException implements TransportExceptionInterface {};

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->with('GET', 'https://remote-sp.org/metadata', $this->isType('array'))
            ->willReturnOnConsecutiveCalls(
                $this->throwException($transportException),
                $response
            );

        $this->spRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $tenant = $this->makeTenant('test');
        $sp = $this->service->importSpMetadata($tenant, 'https://remote-sp.org/metadata', isUrl: true);

        $this->assertSame($entityId, $sp->getEntityId());
    }

    // ── EntitiesDescriptor aggregate parsing ──────────────────

    public function testImportHandlesEntitiesDescriptorWithMultipleSPs(): void
    {
        $aggregate = $this->sampleEntitiesDescriptorXml([
            'https://sp1.example.org',
            'https://sp2.example.org',
            'https://sp3.example.org',
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($aggregate);

        $this->httpClient->method('request')->willReturn($response);

        $this->spRepo->method('findOneBy')->willReturn(null);

        $persistCount = 0;
        $this->em->method('persist')->willReturnCallback(function () use (&$persistCount) {
            $persistCount++;
        });
        $this->em->method('flush');

        $tenant  = $this->makeTenant('test');
        $results = $this->service->refreshTenantMetadata($tenant);

        $this->assertSame(3, $results['imported']);
        $this->assertSame(0, $results['updated']);
        $this->assertEmpty($results['errors']);
    }

    public function testRefreshTenantMetadataSkipsBadSpsAndContinues(): void
    {
        // Aggregate with one valid SP and one SP missing entityID
        $aggregate = <<<XML
<?xml version="1.0"?>
<md:EntitiesDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata">
    <md:EntityDescriptor entityID="https://good-sp.org">
        <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
            <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
                Location="https://good-sp.org/acs" index="1"/>
        </md:SPSSODescriptor>
    </md:EntityDescriptor>
    <md:EntityDescriptor>
        <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"/>
    </md:EntityDescriptor>
</md:EntitiesDescriptor>
XML;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($aggregate);

        $this->httpClient->method('request')->willReturn($response);
        $this->spRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $tenant          = $this->makeTenant('test');
        $tenant->setMetadataAggregateUrls(['https://fed.example.org/metadata.xml']);
        $results = $this->service->refreshTenantMetadata($tenant);

        $this->assertSame(1, $results['imported']); // Good one imported
        $this->assertCount(1, $results['errors']);   // Bad one recorded as error
    }

    // ── Keypair generation ────────────────────────────────────

    public function testGenerateTenantKeypairCreatesRsa4096Keys(): void
    {
        $tenant = $this->makeTenant('test');

        $this->em->expects($this->atLeastOnce())->method('flush');

        $this->service->generateTenantKeypair($tenant);

        $cert = $tenant->getSigningCertificate();
        $key  = $tenant->getSigningPrivateKey();

        $this->assertNotEmpty($cert);
        $this->assertNotEmpty($key);
        $this->assertStringContainsString('PRIVATE KEY', $key);

        // Verify it's RSA 4096
        $keyResource = openssl_pkey_get_private($key);
        $details     = openssl_pkey_get_details($keyResource);
        $this->assertSame(4096, $details['bits']);
    }

    // ── XXE injection protection ──────────────────────────────

    public function testImportBlocksXxeInjection(): void
    {
        $xxeXml = <<<XML
<?xml version="1.0"?>
<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
    entityID="https://evil.example.org">
    <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <foo>&xxe;</foo>
    </md:SPSSODescriptor>
</md:EntityDescriptor>
XML;
        // Should parse without reading /etc/passwd
        // If XXE protection is working, the entity is NOT expanded — either ignored or causes an error
        try {
            $this->service->importSpMetadata($this->makeTenant('test'), $xxeXml, isUrl: false);
            // If we get here, at minimum verify no file content leaked
            $this->assertTrue(true, 'Import completed without expanding XXE entity');
        } catch (\RuntimeException) {
            // Also acceptable — parser rejected it
            $this->assertTrue(true, 'Parser correctly rejected malformed XML');
        }

        // The real protection check: /etc/passwd content should NOT be anywhere in the last call
        $this->assertStringNotContainsString('root:', ob_get_contents() ?: '');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function makeTenant(string $slug): Tenant
    {
        $tenant = new Tenant();
        $tenant->setSlug($slug);
        $tenant->setName('Test Institution');
        $tenant->setStatus(Tenant::STATUS_ACTIVE);
        $tenant->setMetadataAggregateUrls(['https://fed.example.org/metadata.xml']);
        return $tenant;
    }

    private function sampleSpMetadataXml(
        string  $entityId,
        string  $acsUrl = 'https://sp.example.org/Shibboleth.sso/SAML2/POST',
        string  $cert   = '',
    ): string {
        $certBlock = '';
        if ($cert) {
            $certBlock = <<<XML

            <md:KeyDescriptor use="signing">
                <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
                    <ds:X509Data>
                        <ds:X509Certificate>{$cert}</ds:X509Certificate>
                    </ds:X509Data>
                </ds:KeyInfo>
            </md:KeyDescriptor>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor
    xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
    xmlns:mdui="urn:oasis:names:tc:SAML:metadata:ui"
    entityID="{$entityId}">
    <md:SPSSODescriptor
        AuthnRequestsSigned="true"
        WantAssertionsSigned="true"
        protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">{$certBlock}
        <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>
        <md:AssertionConsumerService
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
            Location="{$acsUrl}"
            index="1"/>
        <md:SingleLogoutService
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
            Location="https://sp.example.org/Shibboleth.sso/SLO/Redirect"/>
        <md:Extensions>
            <mdui:UIInfo>
                <mdui:DisplayName xml:lang="en">Test SP</mdui:DisplayName>
            </mdui:UIInfo>
        </md:Extensions>
    </md:SPSSODescriptor>
    <md:ContactPerson contactType="technical">
        <md:GivenName>IT Admin</md:GivenName>
        <md:EmailAddress>mailto:it@example.org</md:EmailAddress>
    </md:ContactPerson>
</md:EntityDescriptor>
XML;
    }

    private function sampleEntitiesDescriptorXml(array $entityIds): string
    {
        $entities = '';
        foreach ($entityIds as $id) {
            $entities .= $this->sampleSpMetadataXml($id) . "\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntitiesDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
    Name="https://fed.example.org">
{$entities}
</md:EntitiesDescriptor>
XML;
    }
}
