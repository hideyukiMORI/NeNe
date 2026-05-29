<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\JobQueue;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JobQueue.
 */
final class JobQueueTest extends TestCase
{
    private PDO $db;
    private JobQueue $jq;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE job_queue (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                type         VARCHAR(100) NOT NULL,
                payload      TEXT         NOT NULL DEFAULT \'{}\',
                status       VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                attempts     INT          NOT NULL DEFAULT 0,
                max_attempts INT          NOT NULL DEFAULT 3,
                error        TEXT         NOT NULL DEFAULT \'\',
                run_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                claimed_at   DATETIME     DEFAULT NULL,
                done_at      DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->jq = new JobQueue($this->db, 3);
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturnsId(): void
    {
        $id = $this->jq->enqueue('send_email');
        $this->assertGreaterThan(0, $id);
    }

    public function testEnqueueStoresPayload(): void
    {
        $id  = $this->jq->enqueue('process', ['key' => 'value']);
        $job = $this->jq->find($id);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(['key' => 'value'], $job['payload']);
    }

    public function testEnqueueSetsStatusToPending(): void
    {
        $id  = $this->jq->enqueue('ping');
        $job = $this->jq->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $job['status']);
    }

    public function testEnqueueThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->jq->enqueue('');
    }

    public function testEnqueueWithDelayIsNotImmediatelyClaimed(): void
    {
        $this->jq->enqueue('delayed', [], 3600);
        $job = $this->jq->claim();
        $this->assertNull($job);
    }

    // ── claim ─────────────────────────────────────────────────────────────────

    public function testClaimReturnsJob(): void
    {
        $this->jq->enqueue('ping');
        $job = $this->jq->claim();
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('running', $job['status']);
    }

    public function testClaimIncrementsAttempts(): void
    {
        $this->jq->enqueue('ping');
        $job = $this->jq->claim();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$job['attempts']);
    }

    public function testClaimReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->jq->claim());
    }

    public function testClaimReturnsJobsInOrder(): void
    {
        $id1 = $this->jq->enqueue('first');
        $id2 = $this->jq->enqueue('second');
        $job = $this->jq->claim();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id1, (int)$job['id']);
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteMarksDone(): void
    {
        $this->jq->enqueue('task');
        $job = $this->jq->claim();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertTrue($this->jq->complete((int)$job['id']));
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $done = $this->jq->find((int)$job['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('done', $done['status']);
    }

    public function testCompleteReturnsFalseIfNotRunning(): void
    {
        $id = $this->jq->enqueue('task');
        $this->assertFalse($this->jq->complete($id));
    }

    // ── fail / retry ──────────────────────────────────────────────────────────

    public function testFailResetsToPendingWhenAttemptsRemain(): void
    {
        $jq = new JobQueue($this->db, 3);
        $jq->enqueue('task');
        $job = $jq->claim(); // attempts = 1, max = 3
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $jq->fail((int)$job['id'], 'timeout');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $updated = $jq->find((int)$job['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $updated['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('timeout', $updated['error']);
    }

    public function testFailMarksFailedWhenMaxAttemptsReached(): void
    {
        $jq = new JobQueue($this->db, 1);
        $jq->enqueue('task');
        $job = $jq->claim(); // attempts = 1, max = 1
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $jq->fail((int)$job['id'], 'fatal');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $updated = $jq->find((int)$job['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $updated['status']);
    }

    public function testFailReturnsFalseIfNotRunning(): void
    {
        $id = $this->jq->enqueue('task');
        $this->assertFalse($this->jq->fail($id, 'oops'));
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function testReleaseResetsToTending(): void
    {
        $this->jq->enqueue('task');
        $job = $this->jq->claim();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertTrue($this->jq->release((int)$job['id']));
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $updated = $this->jq->find((int)$job['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $updated['status']);
    }

    public function testReleaseReturnsFalseIfNotRunning(): void
    {
        $id = $this->jq->enqueue('task');
        $this->assertFalse($this->jq->release($id));
    }

    // ── listPending ───────────────────────────────────────────────────────────

    public function testListPendingReturnsAvailableJobs(): void
    {
        $this->jq->enqueue('a');
        $this->jq->enqueue('b');
        $list = $this->jq->listPending(10);
        $this->assertCount(2, $list);
    }

    public function testListPendingFiltersByType(): void
    {
        $this->jq->enqueue('email');
        $this->jq->enqueue('sms');
        $list = $this->jq->listPending(10, 'email');
        $this->assertCount(1, $list);
        $this->assertSame('email', $list[0]['type']);
    }

    public function testListPendingExcludesRunning(): void
    {
        $this->jq->enqueue('task');
        $this->jq->claim();
        $list = $this->jq->listPending(10);
        $this->assertCount(0, $list);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsAllJobs(): void
    {
        $this->jq->enqueue('a');
        $this->jq->enqueue('b');
        $this->assertSame(2, $this->jq->count());
    }

    public function testCountByStatus(): void
    {
        $this->jq->enqueue('a');
        $this->jq->enqueue('b');
        $this->jq->claim();
        $this->assertSame(1, $this->jq->count('pending'));
        $this->assertSame(1, $this->jq->count('running'));
    }

    public function testCountReturnsZeroForEmptyQueue(): void
    {
        $this->assertSame(0, $this->jq->count());
    }

    // ── purgeCompleted ────────────────────────────────────────────────────────

    public function testPurgeCompletedDeletesOldDoneJobs(): void
    {
        $id = $this->jq->enqueue('task');
        $this->jq->claim();
        $this->jq->complete($id);

        // Manually set done_at to the past
        $this->db->exec("UPDATE job_queue SET done_at = '2000-01-01 00:00:00' WHERE id = {$id}");

        $deleted = $this->jq->purgeCompleted(1);
        $this->assertSame(1, $deleted);
        $this->assertNull($this->jq->find($id));
    }

    public function testPurgeCompletedLeavesRecentJobs(): void
    {
        $this->jq->enqueue('task');
        $job = $this->jq->claim();
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->jq->complete((int)$job['id']);
        $deleted = $this->jq->purgeCompleted(30);
        $this->assertSame(0, $deleted);
    }
}
