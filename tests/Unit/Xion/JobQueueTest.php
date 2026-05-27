<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\JobQueue;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JobQueue using an in-memory SQLite database.
 */
final class JobQueueTest extends TestCase
{
    private PDO $db;
    private JobQueue $q;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE job_queue (
                id           INTEGER      PRIMARY KEY AUTOINCREMENT,
                queue        VARCHAR(64)  NOT NULL DEFAULT \'default\',
                payload      TEXT         NOT NULL,
                status       VARCHAR(16)  NOT NULL DEFAULT \'pending\',
                attempts     INTEGER      NOT NULL DEFAULT 0,
                max_attempts INTEGER      NOT NULL DEFAULT 3,
                error        TEXT         DEFAULT NULL,
                available_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->q = new JobQueue($this->db);
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturnsId(): void
    {
        $id = $this->q->enqueue(['type' => 'email']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testEnqueuedJobIsPending(): void
    {
        $this->q->enqueue(['type' => 'email']);
        $this->assertSame(1, $this->q->count('default', 'pending'));
    }

    public function testEnqueueWithCustomQueue(): void
    {
        $this->q->enqueue(['type' => 'email'], queue: 'emails');
        $this->assertSame(1, $this->q->count('emails', 'pending'));
        $this->assertSame(0, $this->q->count('default', 'pending'));
    }

    public function testEnqueueWithDelay(): void
    {
        $this->q->enqueue(['type' => 'email'], delaySeconds: 3600);
        // Job is not yet available
        $job = $this->q->dequeue();
        $this->assertNull($job);
    }

    // ── dequeue ───────────────────────────────────────────────────────────────

    public function testDequeueReturnsJob(): void
    {
        $this->q->enqueue(['type' => 'email', 'to' => 'a@b.com']);
        $job = $this->q->dequeue();
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('email', $job['payload']['type']);
    }

    public function testDequeueReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->q->dequeue());
    }

    public function testDequeueChangesStatusToProcessing(): void
    {
        $this->q->enqueue(['type' => 'email']);
        $this->q->dequeue();
        $this->assertSame(0, $this->q->count('default', 'pending'));
        $this->assertSame(1, $this->q->count('default', 'processing'));
    }

    public function testDequeueIncrementsAttempts(): void
    {
        $this->q->enqueue(['type' => 'email']);
        $job = $this->q->dequeue();
        $this->assertIsArray($job);
        $this->assertSame(1, $job['attempts']);
    }

    public function testDequeueIsFIFO(): void
    {
        $id1 = $this->q->enqueue(['order' => 1]);
        $id2 = $this->q->enqueue(['order' => 2]);
        $job = $this->q->dequeue();
        $this->assertIsArray($job);
        $this->assertSame($id1, $job['id']);
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteMarksJobDone(): void
    {
        $this->q->enqueue(['type' => 'email']);
        $job = $this->q->dequeue();
        $this->assertIsArray($job);
        $this->q->complete($job['id']);
        $this->assertSame(1, $this->q->count('default', 'completed'));
    }

    // ── fail ──────────────────────────────────────────────────────────────────

    public function testFailWithRemainingAttemptsReQueues(): void
    {
        $this->q->enqueue(['type' => 'email'], maxAttempts: 3);
        $job = $this->q->dequeue();
        $this->assertIsArray($job);
        $this->q->fail($job['id'], 'connection error');
        // 1 attempt used, 2 remaining — status should be pending again
        $this->assertSame(1, $this->q->count('default', 'pending'));
    }

    public function testFailWithNoAttemptsRemainingMarksFailed(): void
    {
        $this->q->enqueue(['type' => 'email'], maxAttempts: 1);
        $job = $this->q->dequeue();
        $this->assertIsArray($job);
        $this->q->fail($job['id'], 'permanent error');
        $this->assertSame(1, $this->q->count('default', 'failed'));
        $this->assertSame(0, $this->q->count('default', 'pending'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAllStatusesWhenNullFilter(): void
    {
        $this->q->enqueue(['a' => 1]);
        $this->q->enqueue(['b' => 2]);
        $this->assertSame(2, $this->q->count('default'));
    }

    public function testCountIsQueueIsolated(): void
    {
        $this->q->enqueue(['a' => 1], queue: 'q1');
        $this->q->enqueue(['b' => 2], queue: 'q2');
        $this->assertSame(1, $this->q->count('q1'));
        $this->assertSame(1, $this->q->count('q2'));
    }
}
