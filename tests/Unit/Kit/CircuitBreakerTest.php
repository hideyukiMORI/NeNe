<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\CircuitBreaker;
use PDO;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE circuit_breaker_states (
                name         VARCHAR(100) NOT NULL PRIMARY KEY,
                state        VARCHAR(20)  NOT NULL DEFAULT \'closed\',
                failures     INT          NOT NULL DEFAULT 0,
                opened_at    DATETIME     NULL,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeCircuit(
        string $name = 'test-service',
        int $failureThreshold = 5,
        int $openSeconds = 60
    ): CircuitBreaker {
        return new CircuitBreaker($name, $this->pdo, $failureThreshold, $openSeconds, 'circuit_breaker_states');
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function testNewCircuitIsAvailable(): void
    {
        $cb = $this->makeCircuit();
        self::assertTrue($cb->isAvailable());
    }

    public function testDefaultStateIsClosedForNewCircuit(): void
    {
        $cb = $this->makeCircuit();
        self::assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
    }

    public function testRecordFailureBelowThresholdKeepsClosed(): void
    {
        $cb = $this->makeCircuit(failureThreshold: 5);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();

        self::assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        self::assertTrue($cb->isAvailable());
    }

    public function testCircuitOpensAtThreshold(): void
    {
        $cb = $this->makeCircuit(failureThreshold: 3);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();

        self::assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
        self::assertFalse($cb->isAvailable());
    }

    public function testOpenCircuitIsUnavailable(): void
    {
        $cb = $this->makeCircuit(failureThreshold: 1, openSeconds: 3600);
        $cb->recordFailure();

        self::assertFalse($cb->isAvailable());
    }

    public function testOpenCircuitBecomesHalfOpenAfterCooldown(): void
    {
        $now = time();
        $cb = $this->makeCircuit(failureThreshold: 1, openSeconds: 60);
        $cb->recordFailure($now);

        // Before cooldown: still OPEN
        self::assertFalse($cb->isAvailable($now + 59));

        // At the cooldown boundary: transitions to HALF-OPEN
        self::assertTrue($cb->isAvailable($now + 60));
        self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());
    }

    public function testOpenCircuitStillUnavailableBeforeCooldown(): void
    {
        $now = time();
        $cb = $this->makeCircuit(failureThreshold: 1, openSeconds: 60);
        $cb->recordFailure($now);

        self::assertFalse($cb->isAvailable($now + 59));
        // State should still be OPEN (not HALF-OPEN yet)
        self::assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
    }

    public function testSuccessAfterHalfOpenClosesCircuit(): void
    {
        $now = time();
        $cb = $this->makeCircuit(failureThreshold: 1, openSeconds: 60);
        $cb->recordFailure($now);

        // Trigger transition to HALF-OPEN
        $cb->isAvailable($now + 60);
        self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // Record success — circuit should close
        $cb->recordSuccess();
        self::assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        self::assertTrue($cb->isAvailable());
    }

    public function testFailureAfterHalfOpenReopensCircuit(): void
    {
        $now = time();
        $cb = $this->makeCircuit(failureThreshold: 1, openSeconds: 60);
        $cb->recordFailure($now);

        // Trigger transition to HALF-OPEN
        $cb->isAvailable($now + 60);
        self::assertSame(CircuitBreaker::STATE_HALF_OPEN, $cb->getState());

        // Record another failure while in HALF-OPEN — circuit should reopen
        $reopenNow = $now + 60;
        $cb->recordFailure($reopenNow);
        self::assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
        self::assertFalse($cb->isAvailable($reopenNow));
    }

    public function testRecordSuccessResetsFailureCount(): void
    {
        $cb = $this->makeCircuit(failureThreshold: 10);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();

        self::assertSame(3, $cb->failureCount());

        $cb->recordSuccess();
        self::assertSame(0, $cb->failureCount());
    }

    public function testResetClearsCircuit(): void
    {
        $cb = $this->makeCircuit(failureThreshold: 1);
        $cb->recordFailure();

        self::assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());

        $cb->reset();
        self::assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());
        self::assertSame(0, $cb->failureCount());
        self::assertTrue($cb->isAvailable());
    }

    public function testCustomThreshold(): void
    {
        $cb = $this->makeCircuit(failureThreshold: 2, openSeconds: 60);
        $cb->recordFailure();
        self::assertSame(CircuitBreaker::STATE_CLOSED, $cb->getState());

        $cb->recordFailure();
        self::assertSame(CircuitBreaker::STATE_OPEN, $cb->getState());
        self::assertFalse($cb->isAvailable());
    }

    public function testFailureCountIsAccurate(): void
    {
        $cb = $this->makeCircuit(failureThreshold: 10);
        self::assertSame(0, $cb->failureCount());

        $cb->recordFailure();
        self::assertSame(1, $cb->failureCount());

        $cb->recordFailure();
        self::assertSame(2, $cb->failureCount());

        $cb->recordFailure();
        self::assertSame(3, $cb->failureCount());
    }

    public function testMultipleCircuitsAreIsolated(): void
    {
        $cbA = new CircuitBreaker('service-a', $this->pdo, 1, 60, 'circuit_breaker_states');
        $cbB = new CircuitBreaker('service-b', $this->pdo, 5, 60, 'circuit_breaker_states');

        $cbA->recordFailure();
        self::assertSame(CircuitBreaker::STATE_OPEN, $cbA->getState());
        self::assertSame(CircuitBreaker::STATE_CLOSED, $cbB->getState());
        self::assertTrue($cbB->isAvailable());
    }
}
