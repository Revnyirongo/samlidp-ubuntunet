<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TenantControllerTest extends WebTestCase
{
    // ── Authentication required ───────────────────────────────

    public function testIndexRedirectsToLoginWhenUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/tenants');

        $this->assertResponseRedirects('/login');
    }

    public function testNewTenantPageRequiresSuperAdmin(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'ROLE_ADMIN');

        $client->loginUser($user);
        $client->request('GET', '/admin/tenants/new');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminCanAccessNewTenantPage(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'ROLE_SUPER_ADMIN');

        $client->loginUser($user);
        $client->request('GET', '/admin/tenants/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testAdminCanOpenEduroamKitForTenant(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'ROLE_ADMIN');
        $tenant = (new Tenant())
            ->setSlug('eduroamtest')
            ->setName('eduroam Test')
            ->setStatus(Tenant::STATUS_ACTIVE)
            ->setAuthType(Tenant::AUTH_DATABASE)
            ->setEntityId('https://eduroamtest.example.com/saml2/idp/metadata.php')
            ->setEduroamProfile(['realm' => 'example.ac.ke']);

        $em->persist($tenant);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin/tenants/' . $tenant->getId()?->toRfc4122() . '/eduroam');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'eduroam');
        $this->assertSelectorTextContains('body', 'example.ac.ke');
    }

    // ── Metadata validation API ───────────────────────────────

    public function testMetadataValidationApiAcceptsValidXml(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'ROLE_ADMIN');
        $client->loginUser($user);

        $validXml = $this->sampleSpMetadataXml('https://sp.example.org');

        $client->request('POST', '/admin/tenants/api/validate-metadata', [
            'xml' => $validXml,
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['valid']);
    }

    public function testMetadataValidationApiRejectsInvalidXml(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'ROLE_ADMIN');
        $client->loginUser($user);

        $client->request('POST', '/admin/tenants/api/validate-metadata', [
            'xml' => 'this is not xml',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['valid']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testMetadataValidationApiReturnsBadRequestWithNoXml(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'ROLE_ADMIN');
        $client->loginUser($user);

        $client->request('POST', '/admin/tenants/api/validate-metadata', []);

        $this->assertResponseStatusCodeSame(400);
    }

    // ── Health check (public) ─────────────────────────────────

    public function testHealthCheckReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('healthy', $data['status']);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function createAdminUser(EntityManagerInterface $em, string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $email = $role . '_' . uniqid() . '@test.example.org';
        $user  = new User();
        $user->setEmail($email);
        $user->setFullName('Test ' . $role);
        $user->setRoles([$role]);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'test_password_123!'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function sampleSpMetadataXml(string $entityId): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor
    xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
    entityID="{$entityId}">
    <md:SPSSODescriptor
        protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <md:AssertionConsumerService
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
            Location="https://sp.example.org/acs"
            index="1"/>
    </md:SPSSODescriptor>
</md:EntityDescriptor>
XML;
    }
}
