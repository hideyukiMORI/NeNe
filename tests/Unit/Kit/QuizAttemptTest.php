<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\QuizAttempt;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuizAttempt.
 */
final class QuizAttemptTest extends TestCase
{
    private PDO $db;
    private QuizAttempt $q;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE quiz_attempts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                quiz       VARCHAR(150) NOT NULL,
                user_id    BIGINT       NOT NULL,
                score      INTEGER      NOT NULL,
                max_score  INTEGER      NOT NULL,
                passed     INTEGER      NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->q = new QuizAttempt($this->db);
    }

    public function testRecordAndAttemptCount(): void
    {
        $this->q->record('php', 1, 7, 10, 6);
        $this->q->record('php', 1, 5, 10, 6);
        $this->assertSame(2, $this->q->attemptCount('php', 1));
    }

    public function testPassMarkBoundary(): void
    {
        $this->q->record('php', 1, 6, 10, 6); // exactly at pass mark → passed
        $this->assertTrue($this->q->hasPassed('php', 1));
        $this->q->record('php', 2, 5, 10, 6); // below → failed
        $this->assertFalse($this->q->hasPassed('php', 2));
    }

    public function testHasPassedTrueIfAnyAttemptPassed(): void
    {
        $this->q->record('php', 1, 5, 10, 6); // fail
        $this->q->record('php', 1, 8, 10, 6); // pass
        $this->assertTrue($this->q->hasPassed('php', 1));
    }

    public function testBestScore(): void
    {
        $this->q->record('php', 1, 5, 10, 6);
        $this->q->record('php', 1, 9, 10, 6);
        $this->q->record('php', 1, 7, 10, 6);
        $this->assertSame(9, $this->q->bestScore('php', 1));
    }

    public function testBestScoreNullWhenNoAttempts(): void
    {
        $this->assertNull($this->q->bestScore('php', 1));
        $this->assertFalse($this->q->hasPassed('php', 1));
        $this->assertSame(0, $this->q->attemptCount('php', 1));
    }

    public function testAttemptsNewestFirst(): void
    {
        $this->q->record('php', 1, 5, 10, 6);
        $this->q->record('php', 1, 8, 10, 6);
        $attempts = $this->q->attempts('php', 1);
        $this->assertCount(2, $attempts);
        $this->assertSame(8, $attempts[0]['score']);   // newest first
        $this->assertTrue($attempts[0]['passed']);
        $this->assertFalse($attempts[1]['passed']);
    }

    public function testPassRate(): void
    {
        $this->q->record('php', 1, 8, 10, 6); // pass
        $this->q->record('php', 2, 7, 10, 6); // pass
        $this->q->record('php', 3, 4, 10, 6); // fail
        $this->q->record('php', 4, 3, 10, 6); // fail
        $this->assertSame(0.5, $this->q->passRate('php'));
    }

    public function testPassRateZeroWhenNoAttempts(): void
    {
        $this->assertSame(0.0, $this->q->passRate('php'));
    }

    public function testQuizzesAndUsersAreSeparate(): void
    {
        $this->q->record('php', 1, 8, 10, 6);
        $this->assertSame(0, $this->q->attemptCount('sql', 1));
        $this->assertSame(0, $this->q->attemptCount('php', 2));
    }

    public function testRecordRejectsScoreAboveMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q->record('php', 1, 11, 10, 6);
    }

    public function testRecordRejectsZeroMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q->record('php', 1, 0, 0, 0);
    }

    public function testRecordRejectsPassMarkAboveMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q->record('php', 1, 5, 10, 11);
    }

    public function testRecordRejectsEmptyQuiz(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q->record('  ', 1, 5, 10, 6);
    }
}
