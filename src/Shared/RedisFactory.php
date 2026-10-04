<?php

declare(strict_types=1);

namespace App\Shared;

use Redis;
use RuntimeException;

final class RedisFactory
{
    public function create(string $dsn): Redis
    {
        $parts = parse_url($dsn);

        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], ['redis', 'tcp'], true)
        ) {
            throw new RuntimeException('Invalid Redis DSN.');
        }

        $port = $parts['port'] ?? 6379;
        $redis = new Redis();
        $connected = $redis->connect($parts['host'], (int) $port, 2.0);

        if (!$connected) {
            throw new RuntimeException('Unable to connect to Redis.');
        }

        if (isset($parts['pass'])) {
            $password = rawurldecode($parts['pass']);

            if (!$redis->auth($password)) {
                throw new RuntimeException('Redis authentication failed.');
            }
        }

        if (isset($parts['path'])) {
            $database = ltrim($parts['path'], '/');

            if ($database !== '' && !$redis->select((int) $database)) {
                throw new RuntimeException('Unable to select Redis database.');
            }
        }

        return $redis;
    }
}
