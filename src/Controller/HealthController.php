<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Redis;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class HealthController
{
    public function __construct(
        private Connection $connection,
        private Redis $redis,
    ) {
    }

    #[Route(
        '/health/live',
        name: 'health_live',
        methods: ['GET'],
    )]
    public function live(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
        ]);
    }

    #[Route(
        '/health/ready',
        name: 'health_ready',
        methods: ['GET'],
    )]
    public function ready(): JsonResponse
    {
        try {
            $databaseResult = $this->connection->fetchOne('SELECT 1');
            $redisResult = $this->redis->ping();

            if ((int) $databaseResult !== 1 || !$redisResult) {
                throw new \RuntimeException('Dependency health check returned an invalid result.');
            }
        } catch (Throwable) {
            return new JsonResponse(
                ['status' => 'unavailable'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse([
            'status' => 'ready',
            'dependencies' => [
                'postgresql' => 'ok',
                'redis' => 'ok',
            ],
        ]);
    }
}
