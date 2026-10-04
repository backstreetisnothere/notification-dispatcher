<?php

declare(strict_types=1);

namespace App\Outbox;

use DateInterval;
use DateTimeImmutable;

final readonly class RetryStrategy
{
    public function __construct(
        private int $baseDelayMilliseconds,
        private int $maxDelayMilliseconds,
        private float $multiplier,
        private float $jitterRatio,
    ) {
        if ($baseDelayMilliseconds < 1 || $maxDelayMilliseconds < $baseDelayMilliseconds) {
            throw new \InvalidArgumentException('Invalid Outbox retry delay configuration.');
        }

        if ($multiplier < 1.0 || $jitterRatio < 0.0 || $jitterRatio > 1.0) {
            throw new \InvalidArgumentException('Invalid Outbox retry multiplier or jitter.');
        }
    }

    public function nextAttemptAt(
        DateTimeImmutable $now,
        int $attempt,
    ): DateTimeImmutable {
        return $now->add(
            new DateInterval(
                sprintf('PT%dS', intdiv($this->delayMilliseconds($attempt), 1000)),
            ),
        )->modify(
            sprintf(
                '+%d milliseconds',
                $this->delayMilliseconds($attempt) % 1000,
            ),
        );
    }

    public function delayMilliseconds(int $attempt): int
    {
        $exponent = max(0, min(30, $attempt - 1));
        $delay = $this->baseDelayMilliseconds * ($this->multiplier ** $exponent);
        $delay = (int) floor(min($this->maxDelayMilliseconds, $delay));

        if ($this->jitterRatio === 0.0) {
            return $delay;
        }

        $jitterRange = (int) floor($delay * $this->jitterRatio);

        if ($jitterRange === 0) {
            return $delay;
        }

        return max(
            1,
            $delay + random_int(-$jitterRange, $jitterRange),
        );
    }
}
