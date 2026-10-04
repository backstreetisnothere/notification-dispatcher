<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Resilience\CircuitBreaker\CircuitPermission;
use App\Resilience\CircuitBreaker\CircuitSnapshot;
use App\Resilience\CircuitBreaker\CircuitState;
use App\Resilience\CircuitBreaker\CircuitStateStore;
use DateInterval;
use DateTimeImmutable;

final class InMemoryCircuitStateStore implements CircuitStateStore
{
    /** @var array<string, array{state: CircuitState, failures: int, openUntil: ?DateTimeImmutable, probe: ?string}> */
    private array $states = [];

    public function acquirePermission(
        string $name,
        DateTimeImmutable $now,
        string $probeToken,
        int $probeLockMilliseconds,
    ): CircuitPermission {
        $state = $this->states[$name] ?? [
            'state' => CircuitState::Closed,
            'failures' => 0,
            'openUntil' => null,
            'probe' => null,
        ];

        if ($state['state'] === CircuitState::Open) {
            if (
                $state['openUntil'] !== null
                && $now < $state['openUntil']
            ) {
                $this->states[$name] = $state;

                return CircuitPermission::Rejected;
            }

            $state['state'] = CircuitState::HalfOpen;
            $state['openUntil'] = null;
            $state['probe'] = null;
        }

        if ($state['state'] === CircuitState::HalfOpen) {
            if ($state['probe'] !== null) {
                $this->states[$name] = $state;

                return CircuitPermission::Rejected;
            }

            $state['probe'] = $probeToken;
            $this->states[$name] = $state;

            return CircuitPermission::AllowedProbe;
        }

        $this->states[$name] = $state;

        return CircuitPermission::AllowedClosed;
    }

    public function recordSuccess(string $name): void
    {
        $this->states[$name] = [
            'state' => CircuitState::Closed,
            'failures' => 0,
            'openUntil' => null,
            'probe' => null,
        ];
    }

    public function recordFailure(
        string $name,
        DateTimeImmutable $now,
        int $failureThreshold,
        int $recoverySeconds,
    ): void {
        $state = $this->states[$name] ?? [
            'state' => CircuitState::Closed,
            'failures' => 0,
            'openUntil' => null,
            'probe' => null,
        ];

        $state['failures']++;
        $state['probe'] = null;

        if (
            $state['state'] === CircuitState::HalfOpen
            || $state['failures'] >= $failureThreshold
        ) {
            $state['state'] = CircuitState::Open;
            $state['openUntil'] = $now->add(
                new DateInterval(sprintf('PT%dS', $recoverySeconds)),
            );
        }

        $this->states[$name] = $state;
    }

    public function snapshot(string $name): CircuitSnapshot
    {
        $state = $this->states[$name] ?? [
            'state' => CircuitState::Closed,
            'failures' => 0,
            'openUntil' => null,
            'probe' => null,
        ];

        return new CircuitSnapshot(
            state: $state['state'],
            failureCount: $state['failures'],
            openUntil: $state['openUntil'],
        );
    }
}
