<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\BatchJob;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BatchJob.
 */
final class BatchJobTest extends TestCase
{
    private PDO $db;
    private BatchJob $bj;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE batch_jobs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                type        VARCHAR(100) NOT NULL,
                owner_id    VARCHAR(255) NOT NULL DEFAULT \'\',
                params      TEXT         NOT NULL DEFAULT \'\',
                status      VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                total       INT          NOT NULL DEFAULT 0,
                processed   INT          NOT NULL DEFAULT 0,
                error       TEXT         NOT NULL DEFAULT \'\',
                enqueued_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at  DATETIME     NULL,
                finished_at DATETIME     NULL
            )
        ');
        $this->bj = new BatchJob($this->db);
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturnsId(): void
    {
        $id = $this->bj->enqueue('import_users', 'user-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testEnqueueStoresParams(): void
    {
        $id  = $this->bj->enqueue('import', 'user-1', ['file' => 'data.csv']);
        $job = $this->bj->find($id);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertStringContainsString('data.csv', $job['params']);
    }

    public function testEnqueueDefaultsToStatusPending(): void
    {
        $id  = $this->bj->enqueue('import');
        $job = $this->bj->find($id);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $job['status']);
    }

    public function testEnqueueThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bj->enqueue('');
    }

    // ── claim ─────────────────────────────────────────────────────────────────

    public function testClaimReturnsOldestPendingJob(): void
    {
        $id1 = $this->bj->enqueue('type-a');
        $this->bj->enqueue('type-b');
        $job = $this->bj->claim();
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id1, (int)$job['id']);
    }

    public function testClaimSetsStatusToProcessing(): void
    {
        $this->bj->enqueue('import');
        $job = $this->bj->claim();
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('processing', $job['status']);
    }

    public function testClaimReturnsNullWhenNoPendingJobs(): void
    {
        $this->assertNull($this->bj->claim());
    }

    public function testClaimDoesNotReturnAlreadyProcessingJob(): void
    {
        $this->bj->enqueue('import');
        $this->bj->claim();
        // No more pending jobs
        $this->assertNull($this->bj->claim());
    }

    // ── progress ──────────────────────────────────────────────────────────────

    public function testProgressUpdatesCounters(): void
    {
        $id = $this->bj->enqueue('import');
        $this->bj->claim();
        $this->bj->progress($id, 50, 200);
        $job = $this->bj->find($id);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(50, (int)$job['processed']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(200, (int)$job['total']);
    }

    public function testProgressWithoutTotalKeepsExistingTotal(): void
    {
        $id = $this->bj->enqueue('import');
        $this->bj->progress($id, 30, 100); // set total first
        $this->bj->progress($id, 60);       // update processed only
        $job = $this->bj->find($id);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(60, (int)$job['processed']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100, (int)$job['total']);
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteSetsDoneStatus(): void
    {
        $id = $this->bj->enqueue('import');
        $this->bj->claim();
        $this->bj->complete($id, 100);
        $job = $this->bj->find($id);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('done', $job['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(100, (int)$job['processed']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($job['finished_at']);
    }

    // ── fail ──────────────────────────────────────────────────────────────────

    public function testFailSetsFailedStatus(): void
    {
        $id = $this->bj->enqueue('import');
        $this->bj->claim();
        $this->bj->fail($id, 'disk full');
        $job = $this->bj->find($id);
        $this->assertNotNull($job);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $job['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('disk full', $job['error']);
    }

    // ── listForOwner ──────────────────────────────────────────────────────────

    public function testListForOwnerReturnsOwnJobs(): void
    {
        $this->bj->enqueue('a', 'user-1');
        $this->bj->enqueue('b', 'user-1');
        $this->bj->enqueue('c', 'user-2');
        $rows = $this->bj->listForOwner('user-1');
        $this->assertCount(2, $rows);
    }

    public function testListForOwnerFiltersByStatus(): void
    {
        $id1 = $this->bj->enqueue('a', 'user-1');
        $this->bj->enqueue('b', 'user-1');
        $this->bj->complete($id1);
        $rows = $this->bj->listForOwner('user-1', 20, 'done');
        $this->assertCount(1, $rows);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAll(): void
    {
        $this->bj->enqueue('a');
        $this->bj->enqueue('b');
        $this->assertSame(2, $this->bj->count());
    }

    public function testCountByStatus(): void
    {
        $id = $this->bj->enqueue('a');
        $this->bj->enqueue('b');
        $this->bj->complete($id);
        $this->assertSame(1, $this->bj->count('done'));
        $this->assertSame(1, $this->bj->count('pending'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesFinishedJobs(): void
    {
        $id1 = $this->bj->enqueue('a');
        $id2 = $this->bj->enqueue('b');
        $this->bj->complete($id1);
        $this->bj->fail($id2, 'error');

        // Manually backdate finished_at
        $old = (new \DateTimeImmutable())->modify('-2 days')->format('Y-m-d H:i:s');
        $this->db->exec("UPDATE batch_jobs SET finished_at = '{$old}'");

        // A still-pending job should not be deleted
        $this->bj->enqueue('c');

        $deleted = $this->bj->purgeOlderThan(1);
        $this->assertSame(2, $deleted);
        $this->assertSame(1, $this->bj->count());
    }
}
