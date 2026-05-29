<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\LoginAttemptTracker;
use PDO;
use PHPUnit\Framework\TestCase;

final class LoginAttemptTrackerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE login_attempts (
                user_id    VARCHAR(255) PRIMARY KEY,
                failures   INT          NOT NULL DEFAULT 0,
                locked_at  DATETIME     NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    private function tracker(int $maxFailures = 5): LoginAttemptTracker
    {
        return new LoginAttemptTracker($this->pdo, $maxFailures, 'login_attempts');
    }

    public function testNewUserIsNotLocked(): void
    {
        self::assertFalse($this->tracker()->isLocked('alice'));
    }

    public function testRecordFailureReturnsIncrementedCount(): void
    {
        $count = $this->tracker()->recordFailure('alice');
        self::assertSame(1, $count);
    }

    public function testMultipleFailuresAccumulate(): void
    {
        $tracker = $this->tracker();
        $tracker->recordFailure('alice');
        $tracker->recordFailure('alice');
        $count = $tracker->recordFailure('alice');
        self::assertSame(3, $count);
        self::assertSame(3, $tracker->failureCount('alice'));
    }

    public function testAccountLocksAfterMaxFailures(): void
    {
        $tracker = $this->tracker();
        for ($i = 0; $i < 5; $i++) {
            $tracker->recordFailure('alice');
        }
        self::assertTrue($tracker->isLocked('alice'));
    }

    public function testAccountNotLockedBelowThreshold(): void
    {
        $tracker = $this->tracker();
        for ($i = 0; $i < 4; $i++) {
            $tracker->recordFailure('alice');
        }
        self::assertFalse($tracker->isLocked('alice'));
    }

    public function testResetClearsFailures(): void
    {
        $tracker = $this->tracker();
        $tracker->recordFailure('alice');
        $tracker->recordFailure('alice');
        $tracker->reset('alice');
        self::assertSame(0, $tracker->failureCount('alice'));
    }

    public function testResetUnlocksAccount(): void
    {
        $tracker = $this->tracker();
        for ($i = 0; $i < 5; $i++) {
            $tracker->recordFailure('alice');
        }
        self::assertTrue($tracker->isLocked('alice'));
        $tracker->reset('alice');
        self::assertFalse($tracker->isLocked('alice'));
    }

    public function testResetOnNonExistentUserDoesNotError(): void
    {
        $tracker = $this->tracker();
        // Should not throw; no prior row for 'ghost'
        $tracker->reset('ghost');
        self::assertSame(0, $tracker->failureCount('ghost'));
    }

    public function testCustomMaxFailures(): void
    {
        $tracker = $this->tracker(maxFailures: 2);
        $tracker->recordFailure('bob');
        self::assertFalse($tracker->isLocked('bob'));
        $tracker->recordFailure('bob');
        self::assertTrue($tracker->isLocked('bob'));
    }

    public function testFailureCountReturnsZeroForUnknownUser(): void
    {
        self::assertSame(0, $this->tracker()->failureCount('nobody'));
    }
}
