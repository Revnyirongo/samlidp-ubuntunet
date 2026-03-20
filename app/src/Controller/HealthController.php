<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface         $cache,
    ) {}

    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function check(): JsonResponse
    {
        $checks = [];
        $overall = true;

        // Database
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
            $overall = false;
        }

        // Redis/Cache
        try {
            $this->cache->get('health_check_probe', fn($item) => 'ok');
            $checks['cache'] = 'ok';
        } catch (\Throwable $e) {
            $checks['cache'] = 'error: ' . $e->getMessage();
            $overall = false;
        }

        return new JsonResponse([
            'status' => $overall ? 'healthy' : 'degraded',
            'checks' => $checks,
            'time'   => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ], $overall ? 200 : 503);
    }
}
