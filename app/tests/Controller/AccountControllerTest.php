<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountControllerTest extends WebTestCase
{
    public function testForgotPasswordPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Reset your password');
    }

    public function testRegisterPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Request administrator access');
    }
}
