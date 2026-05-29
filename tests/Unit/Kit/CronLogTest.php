<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\CronLog;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CronLog.
 */
final class CronLogTest extends TestCase
{
    private PDO $db;
    private CronLog $log;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE cron_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                job_name    VARCHAR(255) NOT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'running\',
                output      TEXT         NOT NULL DEFAULT \'\',
                started_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME     DEFAULT NULL
            )
        ');
        $this->log = new CronLog($this->db);
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartReturnsId(): void
    {
        $id = $this->log->start('cleanup');
        $this->assertGreaterThan(0, $id);
    }

    public function testStartSetsRunningStatus(): void
    {
        $id  = $this->log->start('cleanup');
        $row = $this->log->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('running', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['finished_at']);
    }

    public function testStartThrowsOnEmptyJobName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->start('');
    }

    // ── finish ────────────────────────────────────────────────────────────────

    public function testFinishSetsFinishedStatus(): void
    {
        $id = $this->log->start('cleanup');
        $this->assertTrue($this->log->finish($id, 'Done.'));
        $row = $this->log->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('finished', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Done.', $row['output']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['finished_at']);
    }

    public function testFinishReturnsFalseForNonRunningRun(): void
    {
        $id = $this->log->start('cleanup');
        $this->log->finish($id);
        $this->assertFalse($this->log->finish($id));
    }

    // ── fail ──────────────────────────────────────────────────────────────────

    public function testFailSetsFailedStatus(): void
    {
        $id = $this->log->start('sync');
        $this->assertTrue($this->log->fail($id, 'Timeout'));
        $row = $this->log->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Timeout', $row['output']);
    }

    public function testFailReturnsFalseForNonRunningRun(): void
    {
        $id = $this->log->start('sync');
        $this->log->fail($id);
        $this->assertFalse($this->log->fail($id));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull($this->log->find(999));
    }

    // ── recent ────────────────────────────────────────────────────────────────

    public function testRecentReturnsNewestFirst(): void
    {
        $id1 = $this->log->start('job-a');
        $this->log->finish($id1);
        $id2 = $this->log->start('job-a');
        $this->log->finish($id2);
        $rows = $this->log->recent('job-a', 10);
        $this->assertCount(2, $rows);
        $this->assertSame($id2, (int)$rows[0]['id']);
    }

    public function testRecentRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $id = $this->log->start('job-b');
            $this->log->finish($id);
        }
        $this->assertCount(3, $this->log->recent('job-b', 3));
    }

    public function testRecentIsolatesJobs(): void
    {
        $id = $this->log->start('job-a');
        $this->log->finish($id);
        $this->log->start('job-b');
        $this->assertCount(1, $this->log->recent('job-a'));
    }

    // ── lastSuccess / lastFailure ─────────────────────────────────────────────

    public function testLastSuccess(): void
    {
        $id = $this->log->start('job-a');
        $this->log->finish($id, 'ok');
        $row = $this->log->lastSuccess('job-a');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('finished', $row['status']);
    }

    public function testLastSuccessReturnsNullWhenNone(): void
    {
        $id = $this->log->start('job-a');
        $this->log->fail($id, 'err');
        $this->assertNull($this->log->lastSuccess('job-a'));
    }

    public function testLastFailure(): void
    {
        $id = $this->log->start('job-a');
        $this->log->fail($id, 'err');
        $row = $this->log->lastFailure('job-a');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('failed', $row['status']);
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountAll(): void
    {
        $id1 = $this->log->start('job-a');
        $this->log->finish($id1);
        $id2 = $this->log->start('job-a');
        $this->log->fail($id2);
        $this->assertSame(2, $this->log->count('job-a'));
    }

    public function testCountByStatus(): void
    {
        $id1 = $this->log->start('job-a');
        $this->log->finish($id1);
        $this->log->start('job-a');
        $this->assertSame(1, $this->log->count('job-a', 'finished'));
        $this->assertSame(1, $this->log->count('job-a', 'running'));
        $this->assertSame(0, $this->log->count('job-a', 'failed'));
    }
}
