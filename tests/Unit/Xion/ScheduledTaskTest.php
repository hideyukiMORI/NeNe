<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ScheduledTask;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScheduledTask.
 */
final class ScheduledTaskTest extends TestCase
{
    private PDO $db;
    private ScheduledTask $st;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE scheduled_tasks (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          VARCHAR(100) NOT NULL UNIQUE,
                interval_sec  INTEGER      NOT NULL,
                enabled       TINYINT(1)   NOT NULL DEFAULT 1,
                last_run_at   DATETIME     NULL,
                registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->st = new ScheduledTask($this->db);
    }

    // ── register ──────────────────────────────────────────────────────────────

    public function testRegisterCreatesTask(): void
    {
        $this->st->register('cleanup', 3600);
        $task = $this->st->find('cleanup');
        $this->assertNotNull($task);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(3600, (int)$task['interval_sec']);
    }

    public function testRegisterIsIdempotentAndUpdatesInterval(): void
    {
        $this->st->register('cleanup', 3600);
        $this->st->register('cleanup', 7200);
        $task = $this->st->find('cleanup');
        $this->assertNotNull($task);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(7200, (int)$task['interval_sec']);
        $this->assertCount(1, $this->st->all());
    }

    public function testRegisterThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->st->register('', 3600);
    }

    public function testRegisterThrowsOnNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->st->register('task', 0);
    }

    // ── due ───────────────────────────────────────────────────────────────────

    public function testDueReturnsNewTaskWithNullLastRunAt(): void
    {
        $this->st->register('new-task', 3600);
        $due = $this->st->due();
        $this->assertCount(1, $due);
        $this->assertSame('new-task', $due[0]['name']);
    }

    public function testDueExcludesRecentlyRunTask(): void
    {
        $this->st->register('fresh', 3600);
        $this->st->markRan('fresh');
        $due = $this->st->due();
        $this->assertCount(0, $due);
    }

    public function testDueIncludesOverdueTask(): void
    {
        $past = (new \DateTimeImmutable())->modify('-7201 seconds')->format('Y-m-d H:i:s');
        $this->st->register('overdue', 3600);
        $this->db->exec("UPDATE scheduled_tasks SET last_run_at = '{$past}' WHERE name = 'overdue'");
        $due = $this->st->due();
        $this->assertCount(1, $due);
    }

    public function testDueExcludesDisabledTask(): void
    {
        $this->st->register('disabled', 3600);
        $this->st->disable('disabled');
        $this->assertCount(0, $this->st->due());
    }

    // ── markRan ───────────────────────────────────────────────────────────────

    public function testMarkRanSetsLastRunAt(): void
    {
        $this->st->register('task', 3600);
        $this->assertTrue($this->st->markRan('task'));
        $task = $this->st->find('task');
        $this->assertNotNull($task);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($task['last_run_at']);
    }

    public function testMarkRanReturnsFalseForUnknownTask(): void
    {
        $this->assertFalse($this->st->markRan('nobody'));
    }

    // ── enable / disable ──────────────────────────────────────────────────────

    public function testDisableAndEnable(): void
    {
        $this->st->register('task', 3600);
        $this->assertTrue($this->st->disable('task'));
        $this->assertCount(0, $this->st->due());
        $this->assertTrue($this->st->enable('task'));
        $this->assertCount(1, $this->st->due());
    }

    public function testEnableReturnsFalseForUnknownTask(): void
    {
        $this->assertFalse($this->st->enable('nobody'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllListsAllTasks(): void
    {
        $this->st->register('alpha', 60);
        $this->st->register('beta', 120);
        $this->assertCount(2, $this->st->all());
    }

    public function testAllReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->st->all());
    }

    // ── unregister ────────────────────────────────────────────────────────────

    public function testUnregisterRemovesTask(): void
    {
        $this->st->register('task', 3600);
        $this->assertTrue($this->st->unregister('task'));
        $this->assertNull($this->st->find('task'));
    }

    public function testUnregisterReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->st->unregister('nobody'));
    }
}
