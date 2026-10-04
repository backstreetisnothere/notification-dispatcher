<?php

declare(strict_types=1);

namespace App\Resilience\CircuitBreaker;

use DateTimeImmutable;

final readonly class CircuitSnapshot
{
    public function __construct(
        public CircuitState $state,
        public int $failureCount,
        public ?DateTimeImmutable $openUntil,
    ) {
    }
}
