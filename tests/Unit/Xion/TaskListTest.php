<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TaskList;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TaskList.
 */
final class TaskListTest extends TestCase
{
    private PDO $db;
    private TaskList $tl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE tasks (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                list_name    VARCHAR(100) NOT NULL DEFAULT \'inbox\',
                title        TEXT         NOT NULL,
                completed    TINYINT(1)   NOT NULL DEFAULT 0,
                due_at       DATETIME     DEFAULT NULL,
                completed_at DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->tl = new TaskList($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsId(): void
    {
        $id = $this->tl->add('user-1', 'Buy groceries');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddDefaultListIsInbox(): void
    {
        $id   = $this->tl->add('user-1', 'Task');
        $task = $this->tl->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('inbox', $task['list_name']);
    }

    public function testAddWithCustomList(): void
    {
        $id   = $this->tl->add('user-1', 'Report', 'work');
        $task = $this->tl->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('work', $task['list_name']);
    }

    public function testAddWithDueDate(): void
    {
        $due  = new \DateTimeImmutable('+7 days');
        $id   = $this->tl->add('user-1', 'File taxes', 'inbox', $due);
        $task = $this->tl->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($task['due_at']);
    }

    public function testAddThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tl->add('user-1', '');
    }

    public function testAddThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tl->add('', 'Task');
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteMarksTask(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->assertTrue($this->tl->complete($id, 'user-1'));
        $task = $this->tl->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$task['completed']);
    }

    public function testCompleteReturnsFalseIfAlreadyComplete(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->tl->complete($id, 'user-1');
        $this->assertFalse($this->tl->complete($id, 'user-1'));
    }

    public function testCompleteReturnsFalseForWrongUser(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->assertFalse($this->tl->complete($id, 'user-2'));
    }

    // ── reopen ────────────────────────────────────────────────────────────────

    public function testReopenResetsCompletion(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->tl->complete($id, 'user-1');
        $this->assertTrue($this->tl->reopen($id, 'user-1'));
        $task = $this->tl->find($id);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$task['completed']);
    }

    public function testReopenReturnsFalseIfNotComplete(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->assertFalse($this->tl->reopen($id, 'user-1'));
    }

    // ── rename ────────────────────────────────────────────────────────────────

    public function testRenameUpdatesTitle(): void
    {
        $id = $this->tl->add('user-1', 'Old title');
        $this->assertTrue($this->tl->rename($id, 'user-1', 'New title'));
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New title', $this->tl->find($id)['title']);
    }

    public function testRenameThrowsOnEmptyTitle(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->expectException(\InvalidArgumentException::class);
        $this->tl->rename($id, 'user-1', '');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesTask(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->assertTrue($this->tl->delete($id, 'user-1'));
        $this->assertNull($this->tl->find($id));
    }

    public function testDeleteReturnsFalseForWrongUser(): void
    {
        $id = $this->tl->add('user-1', 'Task');
        $this->assertFalse($this->tl->delete($id, 'user-2'));
    }

    // ── listTasks ─────────────────────────────────────────────────────────────

    public function testListTasksReturnsAllTasks(): void
    {
        $this->tl->add('user-1', 'A');
        $this->tl->add('user-1', 'B');
        $this->assertCount(2, $this->tl->listTasks('user-1'));
    }

    public function testListTasksFiltersByList(): void
    {
        $this->tl->add('user-1', 'Inbox task', 'inbox');
        $this->tl->add('user-1', 'Work task', 'work');
        $list = $this->tl->listTasks('user-1', 'work');
        $this->assertCount(1, $list);
        $this->assertSame('work', $list[0]['list_name']);
    }

    public function testListTasksFiltersByCompleted(): void
    {
        $id1 = $this->tl->add('user-1', 'Done');
        $this->tl->add('user-1', 'Pending');
        $this->tl->complete($id1, 'user-1');
        $pending = $this->tl->listTasks('user-1', null, false);
        $this->assertCount(1, $pending);
    }

    public function testListTasksIsUserScoped(): void
    {
        $this->tl->add('user-1', 'Task 1');
        $this->tl->add('user-2', 'Task 2');
        $this->assertCount(1, $this->tl->listTasks('user-1'));
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsTotal(): void
    {
        $this->tl->add('user-1', 'A');
        $this->tl->add('user-1', 'B');
        $this->assertSame(2, $this->tl->count('user-1'));
    }

    public function testCountFiltersByCompletion(): void
    {
        $id = $this->tl->add('user-1', 'Done');
        $this->tl->add('user-1', 'Pending');
        $this->tl->complete($id, 'user-1');
        $this->assertSame(1, $this->tl->count('user-1', true));
        $this->assertSame(1, $this->tl->count('user-1', false));
    }

    public function testCountReturnsZeroForUserWithNoTasks(): void
    {
        $this->assertSame(0, $this->tl->count('nobody'));
    }
}
