<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\MediaConversionJob;
use PDO;
use PHPUnit\Framework\TestCase;

final class MediaConversionJobTest extends TestCase
{
    private PDO $pdo;
    private MediaConversionJob $mc;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE media_conversion_jobs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                media_type    VARCHAR(50)  NOT NULL,
                source_path   TEXT         NOT NULL,
                output_format VARCHAR(50)  NOT NULL,
                options       TEXT         NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                output_path   TEXT         NULL,
                error         TEXT         NULL,
                attempts      INTEGER      NOT NULL DEFAULT 0,
                started_at    DATETIME     NULL,
                completed_at  DATETIME     NULL,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->mc = new MediaConversionJob($this->pdo);
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturnsId(): void
    {
        $id = $this->mc->enqueue('image', '/uploads/photo.jpg', 'webp');
        $this->assertGreaterThan(0, $id);
    }

    public function testEnqueueStoresFields(): void
    {
        $id  = $this->mc->enqueue('image', '/uploads/photo.jpg', 'webp', ['width' => 800]);
        $row = $this->mc->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('image', $row['media_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('/uploads/photo.jpg', $row['source_path']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('webp', $row['output_format']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(MediaConversionJob::STATUS_PENDING, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertStringContainsString('800', $row['options']);
    }

    public function testEnqueueThrowsOnEmptyMediaType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mc->enqueue('', '/uploads/photo.jpg', 'webp');
    }

    public function testEnqueueThrowsOnEmptySourcePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mc->enqueue('image', '', 'webp');
    }

    public function testEnqueueThrowsOnEmptyOutputFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mc->enqueue('image', '/uploads/photo.jpg', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->mc->get(9999));
    }

    // ── nextPending ───────────────────────────────────────────────────────────

    public function testNextPendingReturnsFifo(): void
    {
        $id1 = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $id2 = $this->mc->enqueue('image', '/b.jpg', 'webp');
        $jobs = $this->mc->nextPending(10);
        $this->assertCount(2, $jobs);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id1, (int)$jobs[0]['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id2, (int)$jobs[1]['id']);
    }

    public function testNextPendingRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->mc->enqueue('image', "/img{$i}.jpg", 'webp');
        }
        $this->assertCount(3, $this->mc->nextPending(3));
    }

    public function testNextPendingExcludesNonPending(): void
    {
        $id = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $this->mc->start($id);
        $this->assertSame([], $this->mc->nextPending());
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartChangesStatus(): void
    {
        $id  = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $this->assertTrue($this->mc->start($id));
        $row = $this->mc->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(MediaConversionJob::STATUS_IN_PROGRESS, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['attempts']);
    }

    public function testStartReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->mc->start(9999));
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteChangesStatus(): void
    {
        $id  = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $this->mc->start($id);
        $this->assertTrue($this->mc->complete($id, '/outputs/a.webp'));
        $row = $this->mc->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(MediaConversionJob::STATUS_COMPLETED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('/outputs/a.webp', $row['output_path']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['completed_at']);
    }

    public function testCompleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->mc->complete(9999, '/out.webp'));
    }

    // ── fail ──────────────────────────────────────────────────────────────────

    public function testFailChangesStatus(): void
    {
        $id  = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $this->mc->start($id);
        $this->assertTrue($this->mc->fail($id, 'OOM error'));
        $row = $this->mc->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(MediaConversionJob::STATUS_FAILED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('OOM error', $row['error']);
    }

    public function testFailReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->mc->fail(9999, 'err'));
    }

    // ── retry ─────────────────────────────────────────────────────────────────

    public function testRetryResetsToPending(): void
    {
        $id = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $this->mc->start($id);
        $this->mc->fail($id, 'err');
        $this->assertTrue($this->mc->retry($id));
        $row = $this->mc->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(MediaConversionJob::STATUS_PENDING, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['error']);
    }

    public function testRetryReturnsFalseIfNotFailed(): void
    {
        $id = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $this->assertFalse($this->mc->retry($id)); // pending, not failed
    }

    // ── byStatus ──────────────────────────────────────────────────────────────

    public function testByStatusFiltersByStatus(): void
    {
        $id1 = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $id2 = $this->mc->enqueue('image', '/b.jpg', 'webp');
        $this->mc->start($id1);
        $this->mc->complete($id1, '/a.webp');
        $completed = $this->mc->byStatus(MediaConversionJob::STATUS_COMPLETED);
        $this->assertCount(1, $completed);
        $pending = $this->mc->byStatus(MediaConversionJob::STATUS_PENDING);
        $this->assertCount(1, $pending);
        unset($id2);
    }

    public function testByStatusReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->mc->byStatus(MediaConversionJob::STATUS_FAILED));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldTerminalJobs(): void
    {
        $this->pdo->exec(
            "INSERT INTO media_conversion_jobs
                 (media_type, source_path, output_format, status, created_at)
             VALUES ('image', '/old.jpg', 'webp', 'completed', '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO media_conversion_jobs
                 (media_type, source_path, output_format, status, created_at)
             VALUES ('image', '/fail.jpg', 'webp', 'failed', '2020-01-02 00:00:00')"
        );
        $deleted = $this->mc->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(2, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeletePending(): void
    {
        $id = $this->mc->enqueue('image', '/a.jpg', 'webp');
        $this->pdo->exec("UPDATE media_conversion_jobs SET created_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->mc->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
