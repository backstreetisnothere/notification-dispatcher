<?php

declare(strict_types=1);

namespace App\Resilience\CircuitBreaker;

enum CircuitPermission: int
{
    case AllowedClosed = 1;
    case AllowedProbe = 2;
    case Rejected = 0;
}
