<?php

declare(strict_types=1);

namespace App\Resilience\CircuitBreaker;

enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
