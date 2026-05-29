<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\MediaProcessing;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaProcessing.
 */
final class MediaProcessingTest extends TestCase
{
    private PDO $db;
    private MediaProcessing $mp;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE media_jobs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id      VARCHAR(64)  NOT NULL UNIQUE,
                owner_id    VARCHAR(255) NOT NULL,
                source_path TEXT         NOT NULL,
                output_path TEXT         DEFAULT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                error_msg   TEXT         DEFAULT NULL,
                attempts    INTEGER      NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->mp = new MediaProcessing($this->db, maxAttempts: 2);
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturns24HexJobId(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/uploads/img.jpg');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $jobId);
    }

    public function testEnqueueSetsPendingStatus(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/uploads/img.jpg');
        $this->assertSame('pending', $this->mp->status($jobId));
    }

    public function testEnqueueThrowsOnEmptyOwnerId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mp->enqueue('', '/uploads/img.jpg');
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartSetsProcessingStatus(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->assertTrue($this->mp->start($jobId));
        $this->assertSame('processing', $this->mp->status($jobId));
    }

    public function testStartIncrementsAttempts(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->mp->start($jobId);
        $job = $this->mp->find($jobId);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$job['attempts']);
    }

    public function testStartReturnsFalseForNonPendingJob(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->mp->start($jobId);
        $this->assertFalse($this->mp->start($jobId)); // already processing
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteSetsReadyStatus(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->mp->start($jobId);
        $this->assertTrue($this->mp->complete($jobId, '/processed/img_thumb.jpg'));
        $this->assertSame('ready', $this->mp->status($jobId));
    }

    public function testCompleteStoresOutputPath(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->mp->start($jobId);
        $this->mp->complete($jobId, '/out/thumb.jpg');
        $job = $this->mp->find($jobId);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('/out/thumb.jpg', $job['output_path']);
    }

    public function testCompleteReturnsFalseIfNotProcessing(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->assertFalse($this->mp->complete($jobId, '/out.jpg'));
    }

    // ── fail ──────────────────────────────────────────────────────────────────

    public function testFailBelowMaxAttemptsResetsToPending(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->mp->start($jobId); // attempts = 1, max = 2
        $this->mp->fail($jobId, 'Timeout');
        $this->assertSame('pending', $this->mp->status($jobId)); // retry
    }

    public function testFailAtMaxAttemptsSetsFailedStatus(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->mp->start($jobId); // attempts = 1
        $this->mp->fail($jobId);  // reset to pending
        $this->mp->start($jobId); // attempts = 2, maxAttempts = 2
        $this->mp->fail($jobId, 'Fatal'); // permanently failed
        $this->assertSame('failed', $this->mp->status($jobId));
    }

    public function testFailReturnsFalseIfNotProcessing(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/img.jpg');
        $this->assertFalse($this->mp->fail($jobId));
    }

    // ── listForOwner / pendingJobs ────────────────────────────────────────────

    public function testListForOwnerReturnsJobs(): void
    {
        $this->mp->enqueue('user-1', '/a.jpg');
        $this->mp->enqueue('user-1', '/b.jpg');
        $this->mp->enqueue('user-2', '/c.jpg');
        $this->assertCount(2, $this->mp->listForOwner('user-1'));
    }

    public function testListForOwnerFiltersByStatus(): void
    {
        $jobId = $this->mp->enqueue('user-1', '/a.jpg');
        $this->mp->start($jobId);
        $this->mp->enqueue('user-1', '/b.jpg');
        $this->assertCount(1, $this->mp->listForOwner('user-1', 'pending'));
        $this->assertCount(1, $this->mp->listForOwner('user-1', 'processing'));
    }

    public function testPendingJobsReturnsOldestFirst(): void
    {
        $j1 = $this->mp->enqueue('user-1', '/a.jpg');
        $j2 = $this->mp->enqueue('user-2', '/b.jpg');
        $pending = $this->mp->pendingJobs(10);
        $this->assertCount(2, $pending);
        $this->assertSame($j1, $pending[0]['job_id']);
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesCompletedJobs(): void
    {
        $this->db->exec(
            "INSERT INTO media_jobs (job_id, owner_id, source_path, status, updated_at)
             VALUES ('oldjob', 'user-1', '/old.jpg', 'ready', '2000-01-01 00:00:00')"
        );
        $this->mp->enqueue('user-1', '/new.jpg'); // recent
        $this->assertSame(1, $this->mp->purgeOlderThan(30));
    }
}
