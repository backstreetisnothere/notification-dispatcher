<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Clock;
use DateInterval;
use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(DateInterval $interval): void
    {
        $this->now = $this->now->add($interval);
    }
}
