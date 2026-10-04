<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Resilience\CircuitBreaker\CircuitPermission;
use App\Resilience\CircuitBreaker\CircuitSnapshot;
use App\Resilience\CircuitBreaker\CircuitState;
use App\Resilience\CircuitBreaker\CircuitStateStore;
use DateTimeImmutable;
use Redis;

final readonly class RedisCircuitStateStore implements CircuitStateStore
{
    private const string ACQUIRE_SCRIPT = <<<'LUA'
        local state = redis.call('HGET', KEYS[1], 'state')

        if not state or state == 'closed' then
            return 1
        end

        if state == 'open' then
            local openUntil = tonumber(redis.call('HGET', KEYS[1], 'open_until') or '0')

            if tonumber(ARGV[1]) < openUntil then
                return 0
            end

            redis.call(
                'HSET',
                KEYS[1],
                'state',
                'half_open',
                'open_until',
                '0'
            )

            state = 'half_open'
        end

        if state == 'half_open' then
            local acquired = redis.call(
                'SET',
                KEYS[2],
                ARGV[2],
                'NX',
                'PX',
                ARGV[3]
            )

            if acquired then
                return 2
            end

            return 0
        end

        return 0
        LUA;

    private const string FAILURE_SCRIPT = <<<'LUA'
        local state = redis.call('HGET', KEYS[1], 'state') or 'closed'

        if state == 'half_open' then
            redis.call(
                'HSET',
                KEYS[1],
                'state',
                'open',
                'failures',
                tonumber(ARGV[2]),
                'open_until',
                tonumber(ARGV[1]) + tonumber(ARGV[3])
            )
        else
            local failures = redis.call('HINCRBY', KEYS[1], 'failures', 1)

            if failures >= tonumber(ARGV[2]) then
                redis.call(
                    'HSET',
                    KEYS[1],
                    'state',
                    'open',
                    'open_until',
                    tonumber(ARGV[1]) + tonumber(ARGV[3])
                )
            else
                redis.call('HSET', KEYS[1], 'failures', failures)
            end
        end

        redis.call('DEL', KEYS[2])

        return 1
        LUA;

    public function __construct(
        private Redis $redis,
        private string $prefix = 'notification-outbox',
    ) {
    }

    public function acquirePermission(
        string $name,
        DateTimeImmutable $now,
        string $probeToken,
        int $probeLockMilliseconds,
    ): CircuitPermission {
        $result = $this->redis->eval(
            self::ACQUIRE_SCRIPT,
            [
                $this->stateKey($name),
                $this->probeKey($name),
                (string) $now->getTimestamp(),
                $probeToken,
                (string) $probeLockMilliseconds,
            ],
            2,
        );

        return CircuitPermission::tryFrom((int) $result)
            ?? CircuitPermission::Rejected;
    }

    public function recordSuccess(string $name): void
    {
        $this->redis->del([
            $this->stateKey($name),
            $this->probeKey($name),
        ]);
    }

    public function recordFailure(
        string $name,
        DateTimeImmutable $now,
        int $failureThreshold,
        int $recoverySeconds,
    ): void
    {
        $this->redis->eval(
            self::FAILURE_SCRIPT,
            [
                $this->stateKey($name),
                $this->probeKey($name),
                (string) $now->getTimestamp(),
                (string) $failureThreshold,
                (string) $recoverySeconds,
            ],
            2,
        );
    }

    public function snapshot(string $name): CircuitSnapshot
    {
        $values = $this->redis->hMGet(
            $this->stateKey($name),
            ['state', 'failures', 'open_until'],
        );

        $state = CircuitState::tryFrom(
            is_string($values['state'] ?? null)
                ? $values['state']
                : CircuitState::Closed->value,
        ) ?? CircuitState::Closed;

        $failureCount = (int) ($values['failures'] ?? 0);
        $openUntilTimestamp = (int) ($values['open_until'] ?? 0);

        return new CircuitSnapshot(
            state: $state,
            failureCount: $failureCount,
            openUntil: $openUntilTimestamp > 0
                ? (new DateTimeImmutable())->setTimestamp($openUntilTimestamp)
                : null,
        );
    }

    private function stateKey(string $name): string
    {
        return sprintf('%s:circuit:%s:state', $this->prefix, hash('sha256', $name));
    }

    private function probeKey(string $name): string
    {
        return sprintf('%s:circuit:%s:probe', $this->prefix, hash('sha256', $name));
    }
}
