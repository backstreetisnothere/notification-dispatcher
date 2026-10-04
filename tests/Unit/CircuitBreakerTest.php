<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Resilience\CircuitBreaker\CircuitBreaker;
use App\Resilience\CircuitBreaker\CircuitOpenException;
use App\Resilience\CircuitBreaker\CircuitState;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\InMemoryCircuitStateStore;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CircuitBreakerTest extends TestCase
{
    public function testItOpensAfterFailureThresholdAndRecoversWithProbe(): void
    {
        $clock = new FrozenClock(
            new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );

        $breaker = new CircuitBreaker(
            store: new InMemoryCircuitStateStore(),
            clock: $clock,
            failureThreshold: 2,
            recoverySeconds: 60,
            probeLockMilliseconds: 1000,
        );

        $calls = 0;
        $operation = static function () use (&$calls): string {
            $calls++;

            throw new RuntimeException('Provider unavailable.');
        };

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $breaker->execute('email-provider', $operation);
                self::fail('Provider failure was not propagated.');
            } catch (RuntimeException $exception) {
                self::assertSame('Provider unavailable.', $exception->getMessage());
            }
        }

        self::assertSame(CircuitState::Open, $breaker->state('email-provider'));
        self::assertSame(2, $calls);

        try {
            $breaker->execute('email-provider', static function (): string {
                self::fail('Open circuit must not invoke the provider.');
            });
            self::fail('Open circuit did not reject the operation.');
        } catch (CircuitOpenException) {
            self::assertTrue(true);
        }

        self::assertSame(2, $calls);

        $clock->advance(new DateInterval('PT61S'));

        $result = $breaker->execute(
            'email-provider',
            static fn (): string => 'accepted',
        );

        self::assertSame('accepted', $result);
        self::assertSame(CircuitState::Closed, $breaker->state('email-provider'));
    }

    public function testSuccessResetsConsecutiveFailureCounter(): void
    {
        $breaker = new CircuitBreaker(
            store: new InMemoryCircuitStateStore(),
            clock: new FrozenClock(
                new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
            ),
            failureThreshold: 2,
            recoverySeconds: 60,
            probeLockMilliseconds: 1000,
        );

        try {
            $breaker->execute(
                'email-provider',
                static function (): void {
                    throw new RuntimeException('Temporary failure.');
                },
            );
        } catch (RuntimeException) {
        }

        $result = $breaker->execute(
            'email-provider',
            static fn (): string => 'success',
        );

        self::assertSame('success', $result);
        self::assertSame(
            CircuitState::Closed,
            $breaker->state('email-provider'),
        );

        try {
            $breaker->execute(
                'email-provider',
                static function (): void {
                    throw new RuntimeException('Another failure.');
                },
            );
        } catch (RuntimeException) {
        }

        self::assertSame(
            CircuitState::Closed,
            $breaker->state('email-provider'),
        );
    }
}
