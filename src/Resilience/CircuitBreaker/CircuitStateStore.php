<?php

declare(strict_types=1);

namespace App\Resilience\CircuitBreaker;

use DateTimeImmutable;

interface CircuitStateStore
{
    public function acquirePermission(
        string $name,
        DateTimeImmutable $now,
        string $probeToken,
        int $probeLockMilliseconds,
    ): CircuitPermission;

    public function recordSuccess(string $name): void;

    public function recordFailure(
        string $name,
        DateTimeImmutable $now,
        int $failureThreshold,
        int $recoverySeconds,
    ): void;

    public function snapshot(string $name): CircuitSnapshot;
}
