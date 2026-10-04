<?php

declare(strict_types=1);

namespace App\Resilience\CircuitBreaker;

use App\Shared\Clock;
use DateInterval;
use Throwable;

final readonly class CircuitBreaker
{
    public function __construct(
        private CircuitStateStore $store,
        private Clock $clock,
        private int $failureThreshold,
        private int $recoverySeconds,
        private int $probeLockMilliseconds,
    ) {
        if (
            $failureThreshold < 1
            || $recoverySeconds < 1
            || $probeLockMilliseconds < 100
        ) {
            throw new \InvalidArgumentException('Invalid Circuit Breaker configuration.');
        }
    }

    public function execute(string $name, callable $operation): mixed
    {
        $now = $this->clock->now();
        $permission = $this->store->acquirePermission(
            name: $name,
            now: $now,
            probeToken: bin2hex(random_bytes(16)),
            probeLockMilliseconds: $this->probeLockMilliseconds,
        );

        if ($permission === CircuitPermission::Rejected) {
            $snapshot = $this->store->snapshot($name);

            throw new CircuitOpenException(
                circuitName: $name,
                retryAt: $snapshot->openUntil,
            );
        }

        try {
            $result = $operation();
            $this->store->recordSuccess($name);

            return $result;
        } catch (Throwable $operationFailure) {
            $this->store->recordFailure(
                name: $name,
                now: $this->clock->now(),
                failureThreshold: $this->failureThreshold,
                recoverySeconds: $this->recoverySeconds,
            );

            throw $operationFailure;
        }
    }

    public function snapshot(string $name): CircuitSnapshot
    {
        return $this->store->snapshot($name);
    }

    public function state(string $name): CircuitState
    {
        return $this->store->snapshot($name)->state;
    }

    public function nextProbeAt(string $name): ?\DateTimeImmutable
    {
        return $this->store->snapshot($name)->openUntil?->add(
            new DateInterval('PT0S'),
        );
    }
}
