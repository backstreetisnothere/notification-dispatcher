<?php

declare(strict_types=1);

namespace App\Resilience\CircuitBreaker;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class CircuitOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $circuitName,
        public readonly ?DateTimeImmutable $retryAt,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Circuit "%s" is open%s.',
                $circuitName,
                $retryAt === null
                    ? ''
                    : sprintf(' until %s', $retryAt->format(DATE_ATOM)),
            ),
            previous: $previous,
        );
    }
}
