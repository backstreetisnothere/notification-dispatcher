<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Application\Notification\DeliveryDeduplicationStore;
use Redis;

final readonly class RedisDeliveryDeduplicationStore implements DeliveryDeduplicationStore
{
    private const string MARK_DELIVERED_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            redis.call('SET', KEYS[2], '1', 'EX', ARGV[2])
            redis.call('DEL', KEYS[1])

            return 1
        end

        return 0
        LUA;

    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end

        return 0
        LUA;

    public function __construct(
        private Redis $redis,
        private string $prefix = 'notification-outbox',
    ) {
    }

    public function isDelivered(string $eventId, string $channel): bool
    {
        return (bool) $this->redis->exists(
            $this->deliveredKey($eventId, $channel),
        );
    }

    public function acquire(
        string $eventId,
        string $channel,
        string $token,
        int $lockTtlMilliseconds,
    ): bool {
        return (bool) $this->redis->set(
            $this->lockKey($eventId, $channel),
            $token,
            [
                'nx',
                'px' => $lockTtlMilliseconds,
            ],
        );
    }

    public function markDelivered(
        string $eventId,
        string $channel,
        string $token,
        int $deliveredTtlSeconds,
    ): bool {
        $result = $this->redis->eval(
            self::MARK_DELIVERED_SCRIPT,
            [
                $this->lockKey($eventId, $channel),
                $this->deliveredKey($eventId, $channel),
                $token,
                (string) $deliveredTtlSeconds,
            ],
            2,
        );

        return (int) $result === 1;
    }

    public function release(
        string $eventId,
        string $channel,
        string $token,
    ): void {
        $this->redis->eval(
            self::RELEASE_SCRIPT,
            [
                $this->lockKey($eventId, $channel),
                $token,
            ],
            1,
        );
    }

    private function hash(string $eventId, string $channel): string
    {
        return hash('sha256', $eventId.':'.$channel);
    }

    private function lockKey(string $eventId, string $channel): string
    {
        return sprintf(
            '%s:deduplication:%s:lock',
            $this->prefix,
            $this->hash($eventId, $channel),
        );
    }

    private function deliveredKey(string $eventId, string $channel): string
    {
        return sprintf(
            '%s:deduplication:%s:delivered',
            $this->prefix,
            $this->hash($eventId, $channel),
        );
    }
}
