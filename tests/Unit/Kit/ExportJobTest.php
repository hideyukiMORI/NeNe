<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ExportJob;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExportJob.
 */
final class ExportJobTest extends TestCase
{
    private PDO $db;
    private ExportJob $ej;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE export_jobs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                format      VARCHAR(50)  NOT NULL DEFAULT \'csv\',
                label       VARCHAR(255) NOT NULL DEFAULT \'\',
                status      VARCHAR(20)  NOT NULL DEFAULT \'pending\',
                filename    VARCHAR(500) NOT NULL DEFAULT \'\',
                row_count   INTEGER      NOT NULL DEFAULT 0,
                error       TEXT         NOT NULL DEFAULT \'\',
                started_at  DATETIME     DEFAULT NULL,
                finished_at DATETIME     DEFAULT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ej = new ExportJob($this->db);
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturnsId(): void
    {
        $id = $this->ej->enqueue('user-1', 'csv', 'orders');
        $this->assertGreaterThan(0, $id);
    }

    public function testEnqueueDefaultsPending(): void
    {
        $id  = $this->ej->enqueue('user-1');
        $job = $this->ej->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $job['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('csv', $job['format']);
    }

    public function testEnqueueThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ej->enqueue('');
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartSetsProcessing(): void
    {
        $id = $this->ej->enqueue('user-1');
        $this->assertTrue($this->ej->start($id));
        $job = $this->ej->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('processing', $job['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($job['started_at']);
    }

    public function testStartReturnsFalseForNonPending(): void
    {
        $id = $this->ej->enqueue('user-1');
        $this->ej->start($id);
        $this->assertFalse($this->ej->start($id));
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testComplete(): void
    {
        $id = $this->ej->enqueue('user-1');
        $this->ej->start($id);
        $this->assertTrue($this->ej->complete($id, 'exports/out.csv', 500));
        $job = $this->ej->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('done', $job['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('exports/out.csv', $job['filename']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(500, (int)$job['row_count']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($job['finished_at']);
    }

    public function testCompleteReturnsFalseIfNotProcessing(): void
    {
        $id = $this->ej->enqueue('user-1');
        $this->assertFalse($this->ej->complete($id, 'out.csv', 0));
    }

    // ── fail ──────────────────────────────────────────────────────────────────

    public function testFail(): void
    {
        $id = $this->ej->enqueue('user-1');
        $this->ej->start($id);
        $this->assertTrue($this->ej->fail($id, 'DB timeout'));
        $job = $this->ej->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $job['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('DB timeout', $job['error']);
    }

    public function testFailReturnsFalseIfNotProcessing(): void
    {
        $id = $this->ej->enqueue('user-1');
        $this->assertFalse($this->ej->fail($id, 'err'));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->ej->find(999));
    }

    // ── listForUser ───────────────────────────────────────────────────────────

    public function testListForUserNewestFirst(): void
    {
        $id1 = $this->ej->enqueue('user-1', 'csv', 'orders');
        $id2 = $this->ej->enqueue('user-1', 'json', 'users');
        $jobs = $this->ej->listForUser('user-1');
        $this->assertCount(2, $jobs);
        $this->assertSame($id2, (int)$jobs[0]['id']);
    }

    public function testListForUserIsolatesUsers(): void
    {
        $this->ej->enqueue('user-1');
        $this->ej->enqueue('user-2');
        $this->assertCount(1, $this->ej->listForUser('user-1'));
    }

    public function testListForUserRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->ej->enqueue('user-1');
        }
        $this->assertCount(3, $this->ej->listForUser('user-1', 3));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAll(): void
    {
        $this->ej->enqueue('user-1');
        $this->ej->enqueue('user-2');
        $this->assertSame(2, $this->ej->count());
    }

    public function testCountByStatus(): void
    {
        $id1 = $this->ej->enqueue('user-1');
        $id2 = $this->ej->enqueue('user-1');
        $this->ej->start($id1);
        $this->ej->complete($id1, 'out.csv', 0);
        $this->ej->start($id2);
        $this->ej->fail($id2, 'err');
        $this->assertSame(1, $this->ej->count('done'));
        $this->assertSame(1, $this->ej->count('failed'));
        $this->assertSame(0, $this->ej->count('pending'));
    }
}
