<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserActionToken;
use App\Repository\UserActionTokenRepository;
use App\Service\UserActionTokenService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class UserActionTokenServiceTest extends TestCase
{
    public function testIssueReturnsOpaqueTokenAndHashesIt(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(UserActionTokenRepository::class);
        $repo->method('findActiveByUserAndPurpose')->willReturn([]);

        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(UserActionToken::class));
        $em->expects($this->once())->method('flush');

        $service = new UserActionTokenService($em, $repo);
        $token = $service->issue((new User())->setEmail('admin@example.org')->setFullName('Admin'), UserActionToken::PURPOSE_PASSWORD_RESET);

        $this->assertSame(64, strlen($token));
    }
}
