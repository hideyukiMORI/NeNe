<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\RetrySchedule;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RetrySchedule.
 */
final class RetryScheduleTest extends TestCase
{
    private PDO $db;
    private RetrySchedule $rs;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE retry_schedule (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                ref             VARCHAR(190) NOT NULL,
                attempts        INTEGER      NOT NULL DEFAULT 0,
                max_attempts    INTEGER      NOT NULL DEFAULT 5,
                base_seconds    INTEGER      NOT NULL DEFAULT 60,
                next_attempt_at DATETIME     NOT NULL,
                exhausted       INTEGER      NOT NULL DEFAULT 0,
                UNIQUE (ref)
            )
        ');
        $this->rs = new RetrySchedule($this->db);
    }

    public function testArmIsDueImmediately(): void
    {
        $this->rs->arm('w', 60, 3, '2026-05-29 12:00:00');
        $this->assertSame(0, $this->rs->attempts('w'));
        $this->assertSame('2026-05-29 12:00:00', $this->rs->nextAttemptAt('w'));
        $due = $this->rs->due('2026-05-29 12:00:00');
        $this->assertCount(1, $due);
        $this->assertSame('w', $due[0]['ref']);
    }

    public function testBackoffExponential(): void
    {
        $this->rs->arm('w', 60, 5, '2026-05-29 12:00:00');
        // attempt 1: delay 60*2^0 = 60s
        $this->assertSame('2026-05-29 12:01:00', $this->rs->backoff('w', '2026-05-29 12:00:00'));
        $this->assertSame(1, $this->rs->attempts('w'));
        // attempt 2: delay 60*2^1 = 120s
        $this->assertSame('2026-05-29 12:03:00', $this->rs->backoff('w', '2026-05-29 12:01:00'));
        // attempt 3: delay 60*2^2 = 240s
        $this->assertSame('2026-05-29 12:07:00', $this->rs->backoff('w', '2026-05-29 12:03:00'));
    }

    public function testBackoffReachesExhaustion(): void
    {
        $this->rs->arm('w', 60, 3, '2026-05-29 12:00:00');
        $this->assertNotNull($this->rs->backoff('w', '2026-05-29 12:00:00')); // attempt 1
        $this->assertNotNull($this->rs->backoff('w', '2026-05-29 12:01:00')); // attempt 2
        $this->assertNull($this->rs->backoff('w', '2026-05-29 12:03:00'));    // attempt 3 → exhausted
        $this->assertTrue($this->rs->isExhausted('w'));
        $this->assertSame(3, $this->rs->attempts('w'));
    }

    public function testExhaustedIsNotDue(): void
    {
        $this->rs->arm('w', 60, 1, '2026-05-29 12:00:00');
        $this->rs->backoff('w', '2026-05-29 12:00:00'); // attempt 1 == max → exhausted
        $this->assertSame([], $this->rs->due('2026-05-29 13:00:00'));
    }

    public function testDueRespectsNextAttemptTime(): void
    {
        $this->rs->arm('w', 60, 5, '2026-05-29 12:00:00');
        $this->rs->backoff('w', '2026-05-29 12:00:00'); // next at 12:01:00
        $this->assertSame([], $this->rs->due('2026-05-29 12:00:30')); // too early
        $this->assertCount(1, $this->rs->due('2026-05-29 12:01:00')); // exactly due (<=)
    }

    public function testDueSoonestFirst(): void
    {
        $this->rs->arm('a', 60, 5, '2026-05-29 12:00:00');
        $this->rs->arm('b', 60, 5, '2026-05-29 11:00:00');
        $due = $this->rs->due('2026-05-29 12:00:00');
        $this->assertSame('b', $due[0]['ref']); // earlier next_attempt_at first
    }

    public function testArmResetsState(): void
    {
        $this->rs->arm('w', 60, 1, '2026-05-29 12:00:00');
        $this->rs->backoff('w', '2026-05-29 12:00:00'); // exhausted
        $this->assertTrue($this->rs->isExhausted('w'));
        $this->rs->arm('w', 60, 3, '2026-05-29 13:00:00'); // re-arm
        $this->assertFalse($this->rs->isExhausted('w'));
        $this->assertSame(0, $this->rs->attempts('w'));
    }

    public function testClear(): void
    {
        $this->rs->arm('w', 60, 3, '2026-05-29 12:00:00');
        $this->rs->clear('w');
        $this->assertSame(0, $this->rs->attempts('w'));
        $this->assertNull($this->rs->nextAttemptAt('w'));
    }

    public function testClearMissingIsNoop(): void
    {
        $this->rs->clear('ghost'); // no throw
        $this->assertFalse($this->rs->isExhausted('ghost'));
    }

    public function testBackoffOnUnarmedThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rs->backoff('never', '2026-05-29 12:00:00');
    }

    public function testArmRejectsEmptyRef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rs->arm('  ');
    }

    public function testArmRejectsZeroBase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rs->arm('w', 0, 3);
    }

    public function testArmRejectsZeroMaxAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rs->arm('w', 60, 0);
    }
}
